<?php

namespace App\Entity;

use App\Repository\GroundTruthConflictRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A `scan_nonce` contradiction — the E3 trigger, persisted.
 *
 * The pre-registration (§3.5, §11) is explicit: a duplicate nonce carrying a different
 * `(participant_token, zone_id, direction)` is *not* a retransmission. It is two irreconcilable
 * claims about one event, the scan is **unresolved**, and E3 excludes the affected interval from
 * every test. "Contradictions are logged, never reconciled by judgement."
 *
 * So this table exists to make the losing claim survive. Overwriting the stored row would destroy
 * the evidence that anything was ever wrong; dropping the incoming one silently would do the same.
 * Both triples are kept verbatim, and the aggregate surfaces the conflict to the operator's console
 * while the session is still running — which is the only moment at which a human can still walk
 * over and find out what actually happened.
 */
#[ORM\Entity(repositoryClass: GroundTruthConflictRepository::class)]
#[ORM\Table(name: 'ground_truth_conflicts')]
#[ORM\Index(name: 'ground_truth_conflict_session_idx', columns: ['lab_session_id'])]
class GroundTruthConflict
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(name: 'lab_session_id', type: 'string', length: 128)]
    private string $labSessionId;

    /**
     * Not unique: one contested nonce can be claimed by three phones, and each disagreement is its
     * own piece of evidence about the interval E3 has to exclude.
     */
    #[ORM\Column(name: 'scan_nonce', type: 'string', length: 128)]
    private string $scanNonce;

    /** The zone of the *stored* claim — the bucket whose interval E3 fires on. */
    #[ORM\Column(name: 'zone_id', type: 'string', length: 128)]
    private string $zoneId;

    /** The triple already on record, `participant|zone|direction`. */
    #[ORM\Column(name: 'accepted_triple', type: 'string', length: 384)]
    private string $acceptedTriple;

    /** The contradicting triple that arrived later and was refused. */
    #[ORM\Column(name: 'rejected_triple', type: 'string', length: 384)]
    private string $rejectedTriple;

    /** Timestamps of the refused claim, so the affected interval can be bounded on the join clock. */
    #[ORM\Column(name: 'rejected_mono_ns', type: 'bigint')]
    private string $rejectedMonoNs;

    #[ORM\Column(name: 'rejected_wall_ms', type: 'bigint')]
    private string $rejectedWallMs;

    #[ORM\Column(name: 'observed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $observedAt;

    public function __construct(
        string $labSessionId,
        string $scanNonce,
        string $zoneId,
        string $acceptedTriple,
        string $rejectedTriple,
        string $rejectedMonoNs,
        string $rejectedWallMs,
    ) {
        $this->id = Uuid::v4();
        $this->labSessionId = $labSessionId;
        $this->scanNonce = $scanNonce;
        $this->zoneId = $zoneId;
        $this->acceptedTriple = $acceptedTriple;
        $this->rejectedTriple = $rejectedTriple;
        $this->rejectedMonoNs = $rejectedMonoNs;
        $this->rejectedWallMs = $rejectedWallMs;
        $this->observedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getLabSessionId(): string
    {
        return $this->labSessionId;
    }

    public function getScanNonce(): string
    {
        return $this->scanNonce;
    }

    public function getZoneId(): string
    {
        return $this->zoneId;
    }

    public function getAcceptedTriple(): string
    {
        return $this->acceptedTriple;
    }

    public function getRejectedTriple(): string
    {
        return $this->rejectedTriple;
    }

    public function getRejectedMonoNs(): int
    {
        return (int) $this->rejectedMonoNs;
    }

    public function getRejectedWallMs(): int
    {
        return (int) $this->rejectedWallMs;
    }

    public function getObservedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }
}
