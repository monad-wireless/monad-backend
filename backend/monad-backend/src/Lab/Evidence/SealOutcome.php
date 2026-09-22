<?php

declare(strict_types=1);

namespace App\Lab\Evidence;

use App\Entity\LabEvidenceManifest;

/**
 * What `POST /api/lab/sessions/{id}/evidence/seal` answers: one of `pending`, `verified`,
 * `invalid`, `conflict`, plus the receipt, the missing artefacts and the reasons.
 */
final class SealOutcome
{
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const INVALID = 'invalid';
    public const CONFLICT = 'conflict';

    /**
     * @param list<string> $missing
     * @param list<string> $reasons
     */
    private function __construct(
        public readonly string $status,
        public readonly ?LabEvidenceManifest $row,
        public readonly array $missing,
        public readonly array $reasons,
        public readonly ?LabEvidenceManifest $accepted = null,
    ) {
    }

    /** @param list<string> $missing */
    public static function of(LabEvidenceManifest $row, array $missing = []): self
    {
        $status = match ($row->getState()) {
            LabEvidenceManifest::STATE_ACCEPTED => self::VERIFIED,
            LabEvidenceManifest::STATE_PENDING => self::PENDING,
            LabEvidenceManifest::STATE_CONFLICT => self::CONFLICT,
            default => self::INVALID,
        };

        return new self($status, $row, $missing, $row->getReasons());
    }

    /** @param list<string> $reasons */
    public static function invalid(?LabEvidenceManifest $row, array $reasons): self
    {
        return new self(self::INVALID, $row, [], $reasons);
    }

    public static function conflict(LabEvidenceManifest $attempt, LabEvidenceManifest $accepted): self
    {
        return new self(self::CONFLICT, $attempt, [], $attempt->getReasons(), $accepted);
    }

    public function httpStatus(): int
    {
        return match ($this->status) {
            self::CONFLICT => 409,
            self::INVALID => $this->row === null ? 400 : 422,
            default => 200,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'manifest_sha256' => $this->row?->getManifestSha256(),
            'missing_artifacts' => $this->missing,
            'reasons' => $this->reasons,
            'receipt' => $this->row?->toReceipt(),
            'accepted_manifest_sha256' => $this->accepted?->getManifestSha256(),
        ];
    }
}
