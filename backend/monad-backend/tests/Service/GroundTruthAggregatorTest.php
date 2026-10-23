<?php

namespace App\Tests\Service;

use App\Entity\GroundTruthConflict;
use App\Entity\GroundTruthScan;
use App\Enum\GroundTruthDirection;
use App\Service\GroundTruthAggregator;
use PHPUnit\Framework\TestCase;

/**
 * The tally arithmetic, exercised without a database.
 *
 * This is the number an operator reads off a bench while ten people walk a staircase, and it is
 * the number the pre-registration's level derivation has to agree with. It is worth testing on its
 * own terms rather than only through HTTP.
 */
class GroundTruthAggregatorTest extends TestCase
{
    private GroundTruthAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new GroundTruthAggregator();
    }

    private function scan(
        string $token,
        string $zone,
        GroundTruthDirection $direction,
        int $monoNs,
        string $session = 'session-1',
    ): GroundTruthScan {
        return new GroundTruthScan(
            labSessionId: $session,
            participantToken: $token,
            zoneId: $zone,
            direction: $direction,
            site: 'fiit-library',
            monoNs: (string) $monoNs,
            wallMs: (string) (1_754_640_000_000 + intdiv($monoNs, 1_000_000)),
            scanNonce: $token . '-' . $zone . '-' . $monoNs,
        );
    }

    public function testEmptySessionIsAnEmptyTallyNotAnError(): void
    {
        // The console polls before the first participant has scanned anything. That has to be a
        // clean zero, not a special case the UI has to know about.
        $result = $this->aggregator->aggregate('session-1', [], []);

        self::assertSame(0, $result['overall']['checked_in']);
        self::assertSame(0, $result['overall']['event_count']);
        self::assertSame([], $result['zones']);
        self::assertNull($result['overall']['last_event_wall_ms']);
    }

    public function testCountsParticipantsCurrentlyInEachZone(): void
    {
        $scans = [
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 1_000),
            $this->scan('p2', 'ZONE-A', GroundTruthDirection::IN, 2_000),
            $this->scan('p3', 'ZONE-B', GroundTruthDirection::IN, 3_000),
            // p2 leaves again — they must stop counting.
            $this->scan('p2', 'ZONE-A', GroundTruthDirection::OUT, 4_000),
        ];

        $result = $this->aggregator->aggregate('session-1', $scans, []);

        $zones = array_column($result['zones'], null, 'zone_id');
        self::assertSame(1, $zones['ZONE-A']['checked_in']);
        self::assertSame(['p1'], $zones['ZONE-A']['participant_tokens']);
        self::assertSame(1, $zones['ZONE-B']['checked_in']);

        self::assertSame(2, $result['overall']['checked_in']);
        self::assertSame(2, $result['overall']['zone_sum']);
        self::assertSame(4, $result['overall']['event_count']);
        self::assertSame(2, $result['overall']['zone_count']);
    }

    public function testLatestScanWinsRegardlessOfArrayOrder(): void
    {
        // Rows can reach the aggregate out of order — several handsets flush independently, and
        // the fold must compare mono_ns by value rather than trusting position.
        $scans = [
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::OUT, 9_000),
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 1_000),
        ];

        $result = $this->aggregator->aggregate('session-1', $scans, []);

        self::assertSame(0, $result['zones'][0]['checked_in'], 'the later OUT must win');
        self::assertSame(9_000, $result['zones'][0]['last_event_mono_ns']);
    }

    public function testNetSumAgreesWithCheckedInOnAlternatingScans(): void
    {
        // The pre-registration defines the level as the cumulative sum of directions. On clean
        // alternating data that must equal the latest-wins count, or the endpoint is reporting a
        // different quantity from the one the study registered.
        $scans = [
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 1_000),
            $this->scan('p2', 'ZONE-A', GroundTruthDirection::IN, 2_000),
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::OUT, 3_000),
            $this->scan('p3', 'ZONE-A', GroundTruthDirection::IN, 4_000),
        ];

        $result = $this->aggregator->aggregate('session-1', $scans, []);

        self::assertSame(2, $result['zones'][0]['checked_in']);
        self::assertSame(2, $result['zones'][0]['net_sum']);
    }

    public function testDoubleScanOfTheSameDirectionDivergesFromNetSum(): void
    {
        // A participant taps IN twice. Latest-wins still says one person is in the zone; the
        // literal cumulative sum says two. Reporting both is what makes the double tap visible
        // during the session instead of during analysis.
        $scans = [
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 1_000),
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 2_000),
        ];

        $result = $this->aggregator->aggregate('session-1', $scans, []);

        self::assertSame(1, $result['zones'][0]['checked_in']);
        self::assertSame(2, $result['zones'][0]['net_sum']);
    }

    public function testRoomTotalCountsAParticipantOnceAcrossZones(): void
    {
        // p1 walks A -> B cleanly. Two zones report, but there is one person in the room.
        $scans = [
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 1_000),
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::OUT, 2_000),
            $this->scan('p1', 'ZONE-B', GroundTruthDirection::IN, 3_000),
        ];

        $result = $this->aggregator->aggregate('session-1', $scans, []);

        self::assertSame(1, $result['overall']['checked_in']);
        self::assertSame(1, $result['overall']['zone_sum']);
        self::assertSame(['p1'], $result['overall']['participant_tokens']);
    }

    public function testForgottenScanOutMakesZoneSumExceedTheRoomTotal(): void
    {
        // p1 walks A -> B but never scans out of A. The room still holds one person; the zones
        // add up to two. The gap is the live "somebody forgot to scan out" signal, so it must
        // survive into the response rather than being smoothed over.
        $scans = [
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 1_000),
            $this->scan('p1', 'ZONE-B', GroundTruthDirection::IN, 2_000),
        ];

        $result = $this->aggregator->aggregate('session-1', $scans, []);

        self::assertSame(1, $result['overall']['checked_in']);
        self::assertSame(2, $result['overall']['zone_sum']);
    }

    public function testStaircaseLevelsAreReportedExactly(): void
    {
        // The registered staircase {0,1,2,3,4,6,8,10}: ten participants enter ZONE-A one at a
        // time, and the tally must read the level back exactly at every step.
        $scans = [];
        $mono = 1_000;
        foreach (range(1, 10) as $i) {
            $scans[] = $this->scan('p' . $i, 'ZONE-A', GroundTruthDirection::IN, $mono);
            $mono += 1_000;

            $result = $this->aggregator->aggregate('session-1', $scans, []);
            self::assertSame($i, $result['overall']['checked_in'], "level after {$i} entries");
            self::assertSame($i, $result['zones'][0]['net_sum']);
        }

        // ...and back down to zero, which is where the cycling blocks end.
        foreach (range(1, 10) as $i) {
            $scans[] = $this->scan('p' . $i, 'ZONE-A', GroundTruthDirection::OUT, $mono);
            $mono += 1_000;
        }
        $result = $this->aggregator->aggregate('session-1', $scans, []);
        self::assertSame(0, $result['overall']['checked_in']);
        self::assertSame(0, $result['zones'][0]['net_sum']);
    }

    public function testConflictsAreSurfacedPerZoneAndOverall(): void
    {
        $conflict = new GroundTruthConflict(
            labSessionId: 'session-1',
            scanNonce: 'nonce-x',
            zoneId: 'ZONE-A',
            acceptedTriple: 'p1|ZONE-A|in',
            rejectedTriple: 'p2|ZONE-A|out',
            rejectedMonoNs: '5000',
            rejectedWallMs: '1754640005000',
        );

        $result = $this->aggregator->aggregate(
            'session-1',
            [$this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 1_000)],
            [$conflict],
        );

        self::assertSame(1, $result['overall']['conflict_count']);
        self::assertSame(1, $result['zones'][0]['conflict_count']);
        self::assertSame('p1|ZONE-A|in', $result['conflicts'][0]['accepted']);
        self::assertSame('p2|ZONE-A|out', $result['conflicts'][0]['rejected']);
    }

    public function testZoneWithOnlyAConflictStillAppears(): void
    {
        // A zone whose only traffic was a contradiction has no scans, but E3 still applies to it.
        // Dropping it from the response would hide the exclusion from the operator.
        $conflict = new GroundTruthConflict(
            labSessionId: 'session-1',
            scanNonce: 'nonce-x',
            zoneId: 'ZONE-C',
            acceptedTriple: 'p1|ZONE-C|in',
            rejectedTriple: 'p2|ZONE-C|in',
            rejectedMonoNs: '5000',
            rejectedWallMs: '1754640005000',
        );

        $result = $this->aggregator->aggregate('session-1', [], [$conflict]);

        self::assertCount(1, $result['zones']);
        self::assertSame('ZONE-C', $result['zones'][0]['zone_id']);
        self::assertSame(0, $result['zones'][0]['checked_in']);
        self::assertSame(1, $result['zones'][0]['conflict_count']);
    }

    public function testZonesAreOrderedStablyForPolling(): void
    {
        // The console repaints every few seconds. Rows that reorder between polls are unreadable.
        $scans = [
            $this->scan('p1', 'ZONE-C', GroundTruthDirection::IN, 1_000),
            $this->scan('p2', 'ZONE-A', GroundTruthDirection::IN, 2_000),
            $this->scan('p3', 'ZONE-B', GroundTruthDirection::IN, 3_000),
        ];

        $result = $this->aggregator->aggregate('session-1', $scans, []);

        self::assertSame(['ZONE-A', 'ZONE-B', 'ZONE-C'], array_column($result['zones'], 'zone_id'));
    }

    public function testLastEventTimestampsTrackTheMostRecentScan(): void
    {
        // The console's staleness indicator is only honest if this is the real newest scan.
        $scans = [
            $this->scan('p1', 'ZONE-A', GroundTruthDirection::IN, 1_000),
            $this->scan('p2', 'ZONE-B', GroundTruthDirection::IN, 7_000),
            $this->scan('p3', 'ZONE-A', GroundTruthDirection::IN, 3_000),
        ];

        $result = $this->aggregator->aggregate('session-1', $scans, []);

        self::assertSame(7_000, $result['overall']['last_event_mono_ns']);
        $zones = array_column($result['zones'], null, 'zone_id');
        self::assertSame(3_000, $zones['ZONE-A']['last_event_mono_ns']);
        self::assertSame(7_000, $zones['ZONE-B']['last_event_mono_ns']);
    }
}
