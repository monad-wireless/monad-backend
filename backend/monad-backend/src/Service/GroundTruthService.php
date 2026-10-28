<?php

namespace App\Service;

use App\Dto\Lab\GroundTruthScanDto;
use App\Entity\GroundTruthConflict;
use App\Entity\GroundTruthScan;
use App\Repository\GroundTruthConflictRepository;
use App\Repository\GroundTruthScanRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Ingest and aggregation for the ground-truth (people) channel.
 *
 * The shape of the problem: ten to twelve handsets each buffer their own participant's scans on
 * SQLite, possibly for hours on an AP with no route to the internet, and each re-uploads its
 * **complete** set on every flush. So this service is written for a stream that is overwhelmingly
 * made of rows it has already seen. Re-sending must be free, must be safe under concurrency, and
 * must never turn one person into two.
 */
class GroundTruthService
{
    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_CONFLICT = 'conflict';
    public const OUTCOME_REJECTED = 'rejected';

    public function __construct(
        private ManagerRegistry $registry,
        private GroundTruthScanRepository $scans,
        private GroundTruthConflictRepository $conflicts,
        private GroundTruthAggregator $aggregator,
    ) {
    }

    /**
     * Persist a batch, idempotently on `scan_nonce`.
     *
     * Returns a per-event outcome rather than a single status, because a batch is one participant's
     * whole session and a single malformed row must not cost the other forty. The HTTP layer maps
     * this to 200 whatever the mix: every outcome here is a *recorded* fact, including the refusals.
     *
     * @param GroundTruthScanDto[] $events
     *
     * @return array{results: list<array<string, string>>, counts: array<string, int>}
     */
    public function ingest(array $events): array
    {
        try {
            return $this->ingestOnce($events);
        } catch (UniqueConstraintViolationException) {
            // Another handset inserted one of these nonces between our lookup and our flush. That
            // is not an error, it is the idempotency key doing its job — the row we wanted now
            // exists. Doctrine closes the manager on a failed flush, so reset it and replay: the
            // second pass sees the winner and classifies our copy as a duplicate or a conflict.
            $this->registry->resetManager();

            return $this->ingestOnce($events);
        }
    }

    /**
     * @param GroundTruthScanDto[] $events
     *
     * @return array{results: list<array<string, string>>, counts: array<string, int>}
     */
    private function ingestOnce(array $events): array
    {
        $manager = $this->registry->getManager();

        $nonces = array_values(array_unique(array_map(
            static fn (GroundTruthScanDto $e): string => $e->scanNonce,
            $events,
        )));

        /** @var array<string, GroundTruthScan> $known nonce => already stored */
        $known = [];
        foreach ($this->scans->findBy(['scanNonce' => $nonces]) as $scan) {
            $known[$scan->getScanNonce()] = $scan;
        }

        $results = [];
        $counts = [
            self::OUTCOME_ACCEPTED => 0,
            self::OUTCOME_DUPLICATE => 0,
            self::OUTCOME_CONFLICT => 0,
            self::OUTCOME_REJECTED => 0,
        ];

        foreach ($events as $event) {
            // A batch can contain the same nonce twice — the device re-renders its whole set, and
            // the first copy in this very payload is already staged. Treat it exactly like a row
            // that was already in the table, so within-batch and across-batch behave identically.
            $existing = $known[$event->scanNonce] ?? null;

            if ($existing === null) {
                $scan = new GroundTruthScan(
                    labSessionId: $event->labSessionId,
                    participantToken: $event->participantToken,
                    zoneId: $event->zoneId,
                    direction: $event->direction,
                    site: $event->site,
                    monoNs: (string) $event->monoNs,
                    wallMs: (string) $event->wallMs,
                    scanNonce: $event->scanNonce,
                    recordingSessionId: $event->recordingSessionId,
                );
                $manager->persist($scan);
                $known[$event->scanNonce] = $scan;
                $results[] = ['scan_nonce' => $event->scanNonce, 'status' => self::OUTCOME_ACCEPTED];
                ++$counts[self::OUTCOME_ACCEPTED];
                continue;
            }

            $sameSession = $existing->getLabSessionId() === $event->labSessionId;
            $sameTriple = $existing->identityTriple() === $event->identityTriple();

            if ($sameSession && $sameTriple) {
                // One scan seen twice. "Keep the earliest by mono_ns" (prereg §3.5) — retransmits
                // can arrive out of order, so the winner is chosen by timestamp value.
                $existing->adoptEarlierObservation($event->monoNs, $event->wallMs);
                $results[] = ['scan_nonce' => $event->scanNonce, 'status' => self::OUTCOME_DUPLICATE];
                ++$counts[self::OUTCOME_DUPLICATE];
                continue;
            }

            // E3. Two irreconcilable claims about one event. The stored row is left untouched and
            // the loser is preserved as evidence — the interval is excluded from every test, and
            // nothing here is entitled to decide which phone was right.
            $manager->persist(new GroundTruthConflict(
                labSessionId: $existing->getLabSessionId(),
                scanNonce: $event->scanNonce,
                zoneId: $existing->getZoneId(),
                acceptedTriple: $existing->identityTriple(),
                rejectedTriple: $event->identityTriple(),
                rejectedMonoNs: (string) $event->monoNs,
                rejectedWallMs: (string) $event->wallMs,
            ));
            $results[] = [
                'scan_nonce' => $event->scanNonce,
                'status' => self::OUTCOME_CONFLICT,
                'reason' => $sameSession
                    ? 'nonce already recorded with a different (participant_token, zone_id, direction)'
                    : 'nonce already recorded against a different lab_session_id',
            ];
            ++$counts[self::OUTCOME_CONFLICT];
        }

        $manager->flush();

        return ['results' => $results, 'counts' => $counts];
    }

    /**
     * The live room tally for one session.
     *
     * Two indexed reads and a fold over a few hundred rows — cheap enough for the console to poll
     * every few seconds for three hours without anyone noticing.
     *
     * @return array<string, mixed>
     */
    public function aggregate(string $labSessionId): array
    {
        return $this->aggregator->aggregate(
            $labSessionId,
            $this->scans->findForSession($labSessionId),
            $this->conflicts->findForSession($labSessionId),
        );
    }
}
