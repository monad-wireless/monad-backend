<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LabReferenceReceiptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One sweep's completion summary, and whether the uploaded evidence backs it (IP-162).
 *
 * A quest completion carries, in the sweep step's `step_data`, a `monad-lab/sweep-summary/v1`:
 * recording, sweep, protocol digest, final event, count and coverage. That summary is a POINTER
 * to the evidence and never a second count stream. This row is where it lands, and its status
 * says how far reconciliation got:
 *
 * - `pending`  — summary accepted; no accepted manifest for the recording yet (upload after
 *                completion, or completion after upload — either order reconciles the same way).
 * - `verified` — an accepted manifest seals the recording, names this sweep and this step, and
 *                the frozen step snapshot carries the same protocol digest.
 * - `invalid`  — the summary contradicts the snapshot or the manifest. Kept, with reasons.
 * - `conflict` — a different summary arrived for a `(recording, sweep)` already received.
 *
 * Human completion status (the enrollment, the points) and this evidence status are separate
 * facts and stay in separate places.
 */
#[ORM\Entity(repositoryClass: LabReferenceReceiptRepository::class)]
#[ORM\Table(name: 'lab_reference_receipts')]
#[ORM\UniqueConstraint(name: 'lab_reference_receipt_sweep', columns: ['recording_session_id', 'sweep_id'])]
#[ORM\Index(name: 'lab_reference_receipt_session_idx', columns: ['recording_session_id'])]
#[ORM\Index(name: 'lab_reference_receipt_status_idx', columns: ['status'])]
class LabReferenceReceipt
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_CONFLICT = 'conflict';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'recording_session_id', length: 128)]
    private string $recordingSessionId;

    #[ORM\Column(name: 'sweep_id', type: 'uuid')]
    private Uuid $sweepId;

    /**
     * The enrollment and the completion this summary names, BY VALUE and not by foreign key.
     *
     * An evidence pointer is a statement about what the phone claimed at the time; it must not
     * be nulled or blocked by what later happens to the row it names. The same posture as
     * `ground_truth_scans.recording_session_id`, and the reason a test may still truncate
     * `quest_step_completions` without this table standing in the way.
     */
    #[ORM\Column(name: 'enrollment_id', type: 'uuid', nullable: true)]
    private ?Uuid $enrollmentId;

    #[ORM\Column(name: 'step_completion_id', type: 'uuid', nullable: true)]
    private ?Uuid $stepCompletionId;

    #[ORM\Column(name: 'protocol_sha256', length: 64)]
    private string $protocolSha256;

    #[ORM\Column(name: 'final_event_id', type: 'uuid', nullable: true)]
    private ?Uuid $finalEventId = null;

    /** The digest of the accepted manifest this receipt was verified against, once there is one. */
    #[ORM\Column(name: 'manifest_sha256', length: 64, nullable: true)]
    private ?string $manifestSha256 = null;

    /** The summary as received. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $summary;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $reasons = [];

    #[ORM\Column(name: 'received_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'reconciled_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $reconciledAt = null;

    /** @param array<string, mixed> $summary */
    public function __construct(
        string $recordingSessionId,
        Uuid $sweepId,
        ?Uuid $enrollmentId,
        ?Uuid $stepCompletionId,
        string $protocolSha256,
        array $summary,
        \DateTimeImmutable $receivedAt,
    ) {
        $this->id = Uuid::v7();
        $this->recordingSessionId = $recordingSessionId;
        $this->sweepId = $sweepId;
        $this->enrollmentId = $enrollmentId;
        $this->stepCompletionId = $stepCompletionId;
        $this->protocolSha256 = $protocolSha256;
        $this->summary = $summary;
        $this->receivedAt = $receivedAt;
        $final = $summary['final_event_id'] ?? null;
        $this->finalEventId = is_string($final) && Uuid::isValid($final) ? Uuid::fromString($final) : null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecordingSessionId(): string
    {
        return $this->recordingSessionId;
    }

    public function getSweepId(): Uuid
    {
        return $this->sweepId;
    }

    public function getEnrollmentId(): ?Uuid
    {
        return $this->enrollmentId;
    }

    public function getStepCompletionId(): ?Uuid
    {
        return $this->stepCompletionId;
    }

    public function getProtocolSha256(): string
    {
        return $this->protocolSha256;
    }

    public function getFinalEventId(): ?Uuid
    {
        return $this->finalEventId;
    }

    public function getManifestSha256(): ?string
    {
        return $this->manifestSha256;
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return $this->summary;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return list<string> */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getReconciledAt(): ?\DateTimeImmutable
    {
        return $this->reconciledAt;
    }

    /** @param list<string> $reasons */
    public function reconcile(string $status, ?string $manifestSha256, array $reasons, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->manifestSha256 = $manifestSha256;
        $this->reasons = array_values($reasons);
        $this->reconciledAt = $at;
    }

    /** @return array<string, mixed> */
    public function toReceipt(): array
    {
        return [
            'recording_session_id' => $this->recordingSessionId,
            'sweep_id' => $this->sweepId->toRfc4122(),
            'enrollment_id' => $this->enrollmentId?->toRfc4122(),
            'step_completion_id' => $this->stepCompletionId?->toRfc4122(),
            'protocol_sha256' => $this->protocolSha256,
            'final_event_id' => $this->finalEventId?->toRfc4122(),
            'manifest_sha256' => $this->manifestSha256,
            'status' => $this->status,
            'reasons' => $this->reasons,
            'count' => $this->summary['count'] ?? null,
            'coverage' => $this->summary['coverage'] ?? null,
            'phase' => $this->summary['phase'] ?? null,
            'received_at' => $this->receivedAt->format(\DateTimeInterface::ATOM),
            'reconciled_at' => $this->reconciledAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
