<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LabEvidenceManifestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One attempt to seal a recording's evidence under `monad-lab/evidence-manifest/v1` (IP-162).
 *
 * The app uploads the manifest after every stream and blob and before the sidecar; the backend
 * verifies each listed hash against the bytes it holds and records the outcome here. Every
 * attempt is kept with its disposition, because a rejected or conflicting manifest is a fact
 * about the recording that a reader must be able to see:
 *
 * - `accepted`  — every listed artefact present and matching. The one seal a recording may hold
 *                 (a partial unique index enforces it).
 * - `pending`   — structurally valid, some artefact not yet present or too large to verify here.
 *                 Re-verified by `app:lab-evidence:reconcile` and by the next seal call.
 * - `invalid`   — structure, ownership or a hash disagreed. Nothing is sealed.
 * - `conflict`  — a different manifest digest arrived for a recording that already holds an
 *                 accepted seal. The accepted one stands; this row records the attempt.
 *
 * `(recording_session_id, manifest_sha256)` is unique, so an identical retry updates its own row
 * and returns the same receipt.
 */
#[ORM\Entity(repositoryClass: LabEvidenceManifestRepository::class)]
#[ORM\Table(name: 'lab_evidence_manifests')]
#[ORM\UniqueConstraint(name: 'lab_evidence_manifest_identity', columns: ['recording_session_id', 'manifest_sha256'])]
#[ORM\Index(name: 'lab_evidence_manifest_session_idx', columns: ['recording_session_id'])]
class LabEvidenceManifest
{
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_PENDING = 'pending';
    public const STATE_INVALID = 'invalid';
    public const STATE_CONFLICT = 'conflict';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** The app's recording id after the register's sanitiser — equals `lab_sessions.id`. */
    #[ORM\Column(name: 'recording_session_id', length: 128)]
    private string $recordingSessionId;

    #[ORM\Column(name: 'manifest_sha256', length: 64)]
    private string $manifestSha256;

    #[ORM\Column(length: 16)]
    private string $state;

    /** The manifest as received, decoded. Its canonical bytes hash to `manifestSha256`. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $manifest;

    /** `{name: {expected, observed, bytes, verified}}` per listed artefact, from the last verification. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $verification = [];

    /** @var list<string> reason codes, empty when accepted */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $reasons = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column(name: 'received_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'verified_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /** @param array<string, mixed> $manifest */
    public function __construct(string $recordingSessionId, string $manifestSha256, array $manifest, ?User $uploadedBy, \DateTimeImmutable $receivedAt)
    {
        $this->id = Uuid::v7();
        $this->recordingSessionId = $recordingSessionId;
        $this->manifestSha256 = $manifestSha256;
        $this->manifest = $manifest;
        $this->uploadedBy = $uploadedBy;
        $this->receivedAt = $receivedAt;
        $this->state = self::STATE_PENDING;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecordingSessionId(): string
    {
        return $this->recordingSessionId;
    }

    public function getManifestSha256(): string
    {
        return $this->manifestSha256;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isAccepted(): bool
    {
        return $this->state === self::STATE_ACCEPTED;
    }

    /** @return array<string, mixed> */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /** @return list<string> */
    public function getSweepIds(): array
    {
        return array_values(array_filter((array) ($this->manifest['sweep_ids'] ?? []), 'is_string'));
    }

    public function getStepCompletionId(): ?string
    {
        $value = $this->manifest['step_completion_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, array<string, mixed>> */
    public function getVerification(): array
    {
        return $this->verification;
    }

    /** @return list<string> */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    /**
     * Record one verification pass.
     *
     * @param array<string, array<string, mixed>> $verification
     * @param list<string> $reasons
     */
    public function record(string $state, array $verification, array $reasons, \DateTimeImmutable $at): void
    {
        $this->state = $state;
        $this->verification = $verification;
        $this->reasons = array_values($reasons);
        $this->verifiedAt = $at;
    }

    /** @return array<string, mixed> the receipt an API caller sees */
    public function toReceipt(): array
    {
        return [
            'recording_session_id' => $this->recordingSessionId,
            'manifest_sha256' => $this->manifestSha256,
            'state' => $this->state,
            'sweep_ids' => $this->getSweepIds(),
            'reasons' => $this->reasons,
            'artifacts' => $this->verification,
            'received_at' => $this->receivedAt->format(\DateTimeInterface::ATOM),
            'verified_at' => $this->verifiedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
