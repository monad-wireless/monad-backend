<?php

namespace App\Service;

use App\Entity\GroundTruthConflict;
use App\Entity\GroundTruthScan;
use App\Enum\GroundTruthDirection;

/**
 * Turns a session's scans into the live room tally.
 *
 * Pure — entities in, array out, no clock and no database — because this is the arithmetic the
 * operator will trust at 10 people in a 341 m² hall, and arithmetic that can only be exercised
 * through HTTP is arithmetic nobody checks.
 *
 * ## The occupancy rule, and why it is not just a sum
 *
 * The pre-registration defines the level as "the cumulative sum of resolved `direction` over
 * `(lab_session_id, zone_id)`" (§3.5). Taken literally that is `Σ ±1`, which is correct exactly
 * when directions alternate per participant — which the handset guarantees, because a toggle code
 * is resolved against that participant's own history.
 *
 * A plain sum is not, however, robust to the one failure that actually happens in a room: two
 * phones scanning the same doorway code within a second of each other, or a participant tapping
 * twice. So the count reported here is **per participant, latest scan wins**, which is
 * idempotent under repetition and agrees with the cumulative sum whenever the data is clean.
 *
 * Both numbers are returned. When `checked_in` and `net_sum` disagree, a participant has scanned
 * the same direction twice in one zone, and that is a data-quality fact the operator should learn
 * during the session rather than during analysis. The console shows the disagreement; it does not
 * pick a winner, because §3.5 says contradictions are logged and never reconciled by judgement.
 *
 * ## Room total vs sum of zones
 *
 * ZONE-A/B/C are three disjoint staged geometries in one hall, and a participant moving A → B
 * scans out of A and into B. `overall.checked_in` therefore counts each participant once — their
 * latest scan **anywhere in the session** — and is the number to compare against a head count.
 * `overall.zone_sum` adds the per-zone counts instead. The two diverge precisely when somebody
 * entered a new zone without scanning out of the old one, so the gap is a live "who forgot to scan
 * out" indicator rather than a rounding artefact.
 */
class GroundTruthAggregator
{
    /**
     * @param GroundTruthScan[]     $scans     ideally ordered by mono_ns; not required, the fold
     *                                         compares timestamps by value
     * @param GroundTruthConflict[] $conflicts
     *
     * @return array<string, mixed>
     */
    public function aggregate(string $labSessionId, array $scans, array $conflicts): array
    {
        /** @var array<string, array<string, GroundTruthScan>> $latestPerZone zone => token => scan */
        $latestPerZone = [];
        /** @var array<string, GroundTruthScan> $latestOverall token => scan */
        $latestOverall = [];
        /** @var array<string, int> $netSum zone => Σ ±1 */
        $netSum = [];
        /** @var array<string, int> $eventCount zone => rows */
        $eventCount = [];
        /** @var array<string, GroundTruthScan> $lastEvent zone => most recent scan */
        $lastEvent = [];

        foreach ($scans as $scan) {
            $zone = $scan->getZoneId();
            $token = $scan->getParticipantToken();

            $eventCount[$zone] = ($eventCount[$zone] ?? 0) + 1;
            $netSum[$zone] = ($netSum[$zone] ?? 0) + $scan->getDirection()->delta();

            if (!isset($lastEvent[$zone]) || $scan->getMonoNs() > $lastEvent[$zone]->getMonoNs()) {
                $lastEvent[$zone] = $scan;
            }
            if (!isset($latestPerZone[$zone][$token])
                || $scan->getMonoNs() > $latestPerZone[$zone][$token]->getMonoNs()) {
                $latestPerZone[$zone][$token] = $scan;
            }
            if (!isset($latestOverall[$token]) || $scan->getMonoNs() > $latestOverall[$token]->getMonoNs()) {
                $latestOverall[$token] = $scan;
            }
        }

        /** @var array<string, int> $conflictsByZone */
        $conflictsByZone = [];
        foreach ($conflicts as $conflict) {
            $conflictsByZone[$conflict->getZoneId()] = ($conflictsByZone[$conflict->getZoneId()] ?? 0) + 1;
        }

        $zones = [];
        // Sorted so the console's zone rows do not reorder themselves between two polls a few
        // seconds apart — a list that reshuffles under the operator's finger is unreadable.
        $zoneIds = array_unique(array_merge(array_keys($eventCount), array_keys($conflictsByZone)));
        sort($zoneIds);

        $zoneSum = 0;
        foreach ($zoneIds as $zoneId) {
            $tokensIn = [];
            foreach ($latestPerZone[$zoneId] ?? [] as $token => $scan) {
                if ($scan->getDirection() === GroundTruthDirection::IN) {
                    $tokensIn[] = $token;
                }
            }
            sort($tokensIn);
            $zoneSum += count($tokensIn);

            $last = $lastEvent[$zoneId] ?? null;
            $zones[] = [
                'zone_id' => $zoneId,
                'checked_in' => count($tokensIn),
                // Opaque pseudonyms, and the only handle an operator has on "someone never scanned
                // out". No name, no e-mail and no device id is derivable from one.
                'participant_tokens' => $tokensIn,
                'net_sum' => $netSum[$zoneId] ?? 0,
                'event_count' => $eventCount[$zoneId] ?? 0,
                'last_event_mono_ns' => $last?->getMonoNs(),
                'last_event_wall_ms' => $last?->getWallMs(),
                'conflict_count' => $conflictsByZone[$zoneId] ?? 0,
            ];
        }

        $overallTokensIn = [];
        foreach ($latestOverall as $token => $scan) {
            if ($scan->getDirection() === GroundTruthDirection::IN) {
                $overallTokensIn[] = $token;
            }
        }
        sort($overallTokensIn);

        $lastOverall = null;
        foreach ($lastEvent as $scan) {
            if ($lastOverall === null || $scan->getMonoNs() > $lastOverall->getMonoNs()) {
                $lastOverall = $scan;
            }
        }

        return [
            'lab_session_id' => $labSessionId,
            // Server clock, so the console can age its own last successful poll against something
            // other than the phone it is running on.
            'server_wall_ms' => (int) round(microtime(true) * 1000),
            'overall' => [
                'checked_in' => count($overallTokensIn),
                'participant_tokens' => $overallTokensIn,
                'zone_sum' => $zoneSum,
                'event_count' => count($scans),
                'zone_count' => count($zones),
                'last_event_mono_ns' => $lastOverall?->getMonoNs(),
                'last_event_wall_ms' => $lastOverall?->getWallMs(),
                'conflict_count' => count($conflicts),
            ],
            'zones' => $zones,
            'conflicts' => array_map(
                static fn (GroundTruthConflict $c): array => [
                    'scan_nonce' => $c->getScanNonce(),
                    'zone_id' => $c->getZoneId(),
                    'accepted' => $c->getAcceptedTriple(),
                    'rejected' => $c->getRejectedTriple(),
                    'rejected_mono_ns' => $c->getRejectedMonoNs(),
                    'rejected_wall_ms' => $c->getRejectedWallMs(),
                    'observed_at' => $c->getObservedAt()->format(\DateTimeInterface::ATOM),
                ],
                array_values($conflicts),
            ),
        ];
    }
}
