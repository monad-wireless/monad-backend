<?php

namespace App\Entity;

use App\Enum\GroundTruthDirection;
use App\Repository\GroundTruthScanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One participant's scan of a printed zone code — the **people** channel, server side.
 *
 * Every other stream this backend receives counts phones. This one only ever advances when a human
 * deliberately points a camera at a code taped to a doorframe, which is precisely why it can serve
 * as truth for a CSI crowd-counting experiment: no beacon observation and no dwell heuristic can
 * manufacture one of these rows.
 *
 * The columns are the pre-registered `ground_truth.tsv` contract, verbatim and in order
 * (`lab-session-2026-08-prereg-v2.md` §3.5). They are frozen: the analysis side joins on
 * `mono_ns`, and renaming a column here would silently break a study that has already been
 * registered. `received_at` is the one addition and is server-side provenance only — it never
 * participates in the science, because a phone that spent three hours on an AP with no route to
 * the internet uploads its whole session at once and every row would carry the same arrival time.
 *
 * **Privacy posture — count without identify.** `participant_token` is an opaque pseudonym minted
 * on the handset. There is deliberately no relation to `User` here: the account belongs to the
 * game, the dataset carries only the pseudonym, and a join between the two must not be expressible
 * in the schema.
 */
#[ORM\Entity(repositoryClass: GroundTruthScanRepository::class)]
#[ORM\Table(name: 'ground_truth_scans')]
// The idempotency key. A phone re-uploads its complete set on every flush, so the same nonce
// arrives many times over a session; the database, not the application, is what makes that safe
// when twelve handsets flush concurrently.
#[ORM\UniqueConstraint(name: 'ground_truth_scan_nonce_idx', columns: ['scan_nonce'])]
// The aggregate reads one session at a time and buckets by zone. Both live polls hit this index.
#[ORM\Index(name: 'ground_truth_session_zone_idx', columns: ['lab_session_id', 'zone_id'])]
class GroundTruthScan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    /** The session the scan is truth *for* — from the scanned code, not from the scanning phone. */
    #[ORM\Column(name: 'lab_session_id', type: 'string', length: 128)]
    private string $labSessionId;

    /** Opaque participant pseudonym. Never a name, never the account e-mail. */
    #[ORM\Column(name: 'participant_token', type: 'string', length: 128)]
    private string $participantToken;

    /** PostGIS cell id of the zone whose entrance carries the code (ZONE-A / ZONE-B / ZONE-C). */
    #[ORM\Column(name: 'zone_id', type: 'string', length: 128)]
    private string $zoneId;

    #[ORM\Column(name: 'direction', type: 'string', length: 8, enumType: GroundTruthDirection::class)]
    private GroundTruthDirection $direction;

    #[ORM\Column(name: 'site', type: 'string', length: 128)]
    private string $site = '';

    /**
     * Device monotonic nanoseconds — the clock every other sample stream is stamped with, and the
     * column the analysis side joins on. `bigint` because nanoseconds since boot overflow int32
     * after ~2 s; mapped to string in PHP by Doctrine, so accessors cast.
     */
    #[ORM\Column(name: 'mono_ns', type: 'bigint')]
    private string $monoNs;

    /** Unix epoch milliseconds, carried alongside exactly as the other streams carry it. */
    #[ORM\Column(name: 'wall_ms', type: 'bigint')]
    private string $wallMs;

    /** Client-generated idempotency key. See the unique constraint above. */
    #[ORM\Column(name: 'scan_nonce', type: 'string', length: 128)]
    private string $scanNonce;

    /**
     * The local recording session open when the scan happened, if any. Provenance only — a scan is
     * valid whether or not that phone was instrumenting, which is the point: a participant who
     * arrives before the operator starts the run is still a person in the room.
     */
    #[ORM\Column(name: 'recording_session_id', type: 'string', length: 128, nullable: true)]
    private ?string $recordingSessionId = null;

    /** Server arrival time. Provenance for the operator, never an input to the science. */
    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    public function __construct(
        string $labSessionId,
        string $participantToken,
        string $zoneId,
        GroundTruthDirection $direction,
        string $site,
        string $monoNs,
        string $wallMs,
        string $scanNonce,
        ?string $recordingSessionId = null,
    ) {
        $this->id = Uuid::v4();
        $this->labSessionId = $labSessionId;
        $this->participantToken = $participantToken;
        $this->zoneId = $zoneId;
        $this->direction = $direction;
        $this->site = $site;
        $this->monoNs = $monoNs;
        $this->wallMs = $wallMs;
        $this->scanNonce = $scanNonce;
        $this->recordingSessionId = $recordingSessionId;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getLabSessionId(): string
    {
        return $this->labSessionId;
    }

    public function getParticipantToken(): string
    {
        return $this->participantToken;
    }

    public function getZoneId(): string
    {
        return $this->zoneId;
    }

    public function getDirection(): GroundTruthDirection
    {
        return $this->direction;
    }

    public function getSite(): string
    {
        return $this->site;
    }

    public function getMonoNs(): int
    {
        return (int) $this->monoNs;
    }

    public function getWallMs(): int
    {
        return (int) $this->wallMs;
    }

    public function getScanNonce(): string
    {
        return $this->scanNonce;
    }

    public function getRecordingSessionId(): ?string
    {
        return $this->recordingSessionId;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    /**
     * The identity triple the contradiction rule is defined over.
     *
     * Two rows sharing a nonce but differing here are not one scan seen twice; they are two claims
     * about what happened, and the pre-registration forbids resolving that by judgement.
     */
    public function identityTriple(): string
    {
        return $this->participantToken . '|' . $this->zoneId . '|' . $this->direction->value;
    }

    /**
     * Adopt an earlier observation of the *same* scan.
     *
     * "Dedup on `scan_nonce`, keep the earliest by `mono_ns`" (prereg §3.5). Retransmissions can
     * arrive out of order — a phone that buffered for an hour flushes after one that scanned later
     * — so the winning timestamp is chosen by value, not by arrival.
     */
    public function adoptEarlierObservation(int $monoNs, int $wallMs): bool
    {
        if ($monoNs >= (int) $this->monoNs) {
            return false;
        }

        $this->monoNs = (string) $monoNs;
        $this->wallMs = (string) $wallMs;

        return true;
    }
}
