<?php

namespace App\Tests\Service;

use App\Dto\Lab\GroundTruthScanDto;
use App\Service\GroundTruthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ingest against a real PostgreSQL.
 *
 * Deliberately not a mocked repository: the idempotency guarantee this endpoint makes *is* a UNIQUE
 * index, and a double that returns whatever it was told cannot fail the way the database can. The
 * behaviour under test — twelve handsets re-uploading overlapping sets — only exists at the storage
 * layer.
 */
class GroundTruthServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GroundTruthService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(GroundTruthService::class);

        // Each test owns the tables outright — the tally is a whole-session aggregate, so leftovers
        // from a neighbouring test would not fail loudly, they would just make the counts wrong.
        $this->em->getConnection()->executeStatement('TRUNCATE ground_truth_scans, ground_truth_conflicts');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }

    private function dto(
        string $nonce,
        string $token = 'p1',
        string $zone = 'ZONE-A',
        string $direction = 'in',
        int $monoNs = 1_000,
        string $session = 'session-1',
    ): GroundTruthScanDto {
        return GroundTruthScanDto::fromArray([
            'lab_session_id' => $session,
            'participant_token' => $token,
            'zone_id' => $zone,
            'direction' => $direction,
            'site' => 'fiit-library',
            'mono_ns' => $monoNs,
            'wall_ms' => 1_754_640_000_000 + intdiv($monoNs, 1_000_000),
            'scan_nonce' => $nonce,
        ]);
    }

    public function testFirstSubmissionIsAccepted(): void
    {
        $result = $this->service->ingest([$this->dto('n1')]);

        self::assertSame(1, $result['counts'][GroundTruthService::OUTCOME_ACCEPTED]);
        self::assertSame('accepted', $result['results'][0]['status']);

        $tally = $this->service->aggregate('session-1');
        self::assertSame(1, $tally['overall']['checked_in']);
    }

    public function testResubmittingTheSameScanIsFreeAndDoesNotDoubleCount(): void
    {
        // The real traffic pattern: a handset re-renders its complete set on every flush, so the
        // same nonce arrives on every single upload for the rest of the session.
        $this->service->ingest([$this->dto('n1')]);
        $second = $this->service->ingest([$this->dto('n1')]);
        $third = $this->service->ingest([$this->dto('n1')]);

        self::assertSame(1, $second['counts'][GroundTruthService::OUTCOME_DUPLICATE]);
        self::assertSame(0, $second['counts'][GroundTruthService::OUTCOME_ACCEPTED]);
        self::assertSame(1, $third['counts'][GroundTruthService::OUTCOME_DUPLICATE]);

        $tally = $this->service->aggregate('session-1');
        self::assertSame(1, $tally['overall']['checked_in'], 'one person, not three');
        self::assertSame(1, $tally['overall']['event_count']);
    }

    public function testDuplicateNonceInsideOneBatchIsCollapsed(): void
    {
        // A single payload can carry the same nonce twice. Within-batch and across-batch must
        // behave identically, or the count depends on how the client happened to chunk its flush.
        $result = $this->service->ingest([$this->dto('n1'), $this->dto('n1')]);

        self::assertSame(1, $result['counts'][GroundTruthService::OUTCOME_ACCEPTED]);
        self::assertSame(1, $result['counts'][GroundTruthService::OUTCOME_DUPLICATE]);
        self::assertSame(1, $this->service->aggregate('session-1')['overall']['event_count']);
    }

    public function testEarliestMonotonicTimestampWinsEvenWhenItArrivesLast(): void
    {
        // "Dedup on scan_nonce, keep the earliest by mono_ns" (prereg §3.5). Retransmits arrive out
        // of order, so the winner is chosen by value rather than by arrival.
        $this->service->ingest([$this->dto('n1', monoNs: 5_000)]);
        $this->service->ingest([$this->dto('n1', monoNs: 2_000)]);

        $tally = $this->service->aggregate('session-1');
        self::assertSame(2_000, $tally['overall']['last_event_mono_ns']);
    }

    public function testLaterTimestampDoesNotOverwriteTheEarliest(): void
    {
        $this->service->ingest([$this->dto('n1', monoNs: 2_000)]);
        $this->service->ingest([$this->dto('n1', monoNs: 9_000)]);

        $tally = $this->service->aggregate('session-1');
        self::assertSame(2_000, $tally['overall']['last_event_mono_ns']);
    }

    public function testContradictingTripleIsFlaggedAndNeverOverwrites(): void
    {
        // Exclusion E3. Same nonce, different (participant_token, zone_id, direction) — two
        // irreconcilable claims about one event.
        $this->service->ingest([$this->dto('n1', token: 'p1', direction: 'in')]);
        $result = $this->service->ingest([$this->dto('n1', token: 'p2', direction: 'out')]);

        self::assertSame(1, $result['counts'][GroundTruthService::OUTCOME_CONFLICT]);
        self::assertSame(0, $result['counts'][GroundTruthService::OUTCOME_ACCEPTED]);
        self::assertSame('conflict', $result['results'][0]['status']);

        $tally = $this->service->aggregate('session-1');

        // The stored claim is untouched...
        self::assertSame(1, $tally['overall']['checked_in']);
        self::assertSame(['p1'], $tally['overall']['participant_tokens']);
        // ...and the refused one survives as evidence, on the record, for the operator to see now.
        self::assertSame(1, $tally['overall']['conflict_count']);
        self::assertSame('p1|ZONE-A|in', $tally['conflicts'][0]['accepted']);
        self::assertSame('p2|ZONE-A|out', $tally['conflicts'][0]['rejected']);
    }

    public function testSameNonceUnderADifferentSessionIsAlsoAContradiction(): void
    {
        // A nonce reused across sessions would otherwise splice two sessions' scans together
        // silently, which is worse than any single wrong count.
        $this->service->ingest([$this->dto('n1', session: 'session-1')]);
        $result = $this->service->ingest([$this->dto('n1', session: 'session-2')]);

        self::assertSame(1, $result['counts'][GroundTruthService::OUTCOME_CONFLICT]);
        self::assertSame(0, $this->service->aggregate('session-2')['overall']['event_count']);
        self::assertSame(1, $this->service->aggregate('session-1')['overall']['conflict_count']);
    }

    public function testRepeatedContradictionsAreEachRecorded(): void
    {
        // One contested nonce can be claimed by several phones, and each disagreement bounds the
        // interval E3 has to exclude.
        $this->service->ingest([$this->dto('n1', token: 'p1')]);
        $this->service->ingest([$this->dto('n1', token: 'p2')]);
        $this->service->ingest([$this->dto('n1', token: 'p3')]);

        self::assertSame(2, $this->service->aggregate('session-1')['overall']['conflict_count']);
    }

    public function testConflictDoesNotBlockGoodRowsInTheSameBatch(): void
    {
        // One participant's bad scan must not cost the room its tally.
        $this->service->ingest([$this->dto('n1', token: 'p1')]);

        $result = $this->service->ingest([
            $this->dto('n1', token: 'p9', direction: 'out'),
            $this->dto('n2', token: 'p2', monoNs: 2_000),
            $this->dto('n3', token: 'p3', monoNs: 3_000),
        ]);

        self::assertSame(2, $result['counts'][GroundTruthService::OUTCOME_ACCEPTED]);
        self::assertSame(1, $result['counts'][GroundTruthService::OUTCOME_CONFLICT]);
        self::assertSame(3, $this->service->aggregate('session-1')['overall']['checked_in']);
    }

    public function testRoomWideTallyAggregatesAcrossParticipantsAndZones(): void
    {
        // The whole point of the endpoint: twelve handsets, one number. Each participant only ever
        // reports their own scans, and nobody's device sees more than one person.
        $mono = 1_000;
        foreach (['p1', 'p2', 'p3', 'p4'] as $i => $token) {
            $this->service->ingest([
                $this->dto('a-' . $token, token: $token, zone: 'ZONE-A', monoNs: $mono += 1_000),
            ]);
        }
        foreach (['p5', 'p6'] as $token) {
            $this->service->ingest([
                $this->dto('b-' . $token, token: $token, zone: 'ZONE-B', monoNs: $mono += 1_000),
            ]);
        }
        // p1 leaves ZONE-A for ZONE-C.
        $this->service->ingest([$this->dto('a-p1-out', token: 'p1', zone: 'ZONE-A', direction: 'out', monoNs: $mono += 1_000)]);
        $this->service->ingest([$this->dto('c-p1', token: 'p1', zone: 'ZONE-C', monoNs: $mono += 1_000)]);

        $tally = $this->service->aggregate('session-1');

        self::assertSame(6, $tally['overall']['checked_in']);
        self::assertSame(6, $tally['overall']['zone_sum']);
        $zones = array_column($tally['zones'], null, 'zone_id');
        self::assertSame(3, $zones['ZONE-A']['checked_in']);
        self::assertSame(2, $zones['ZONE-B']['checked_in']);
        self::assertSame(1, $zones['ZONE-C']['checked_in']);
    }

    public function testUnknownSessionIsAnEmptyTally(): void
    {
        // The console polls the moment a session starts, before anyone has scanned. That must not
        // be an error state the operator has to interpret.
        $tally = $this->service->aggregate('never-seen');

        self::assertSame(0, $tally['overall']['checked_in']);
        self::assertSame([], $tally['zones']);
    }

    public function testScansAreScopedToTheirOwnSession(): void
    {
        $this->service->ingest([$this->dto('n1', token: 'p1', session: 'session-1')]);
        $this->service->ingest([$this->dto('n2', token: 'p2', session: 'session-2')]);

        self::assertSame(1, $this->service->aggregate('session-1')['overall']['checked_in']);
        self::assertSame(1, $this->service->aggregate('session-2')['overall']['checked_in']);
    }
}
