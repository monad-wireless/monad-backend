<?php

namespace App\Dto\Lab;

use App\Enum\GroundTruthDirection;

/**
 * One ground-truth scan as it arrives on the wire.
 *
 * **Field names are snake_case, and that is deliberate** even though the quest DTOs next door are
 * camelCase. These nine names are a frozen pre-registration contract
 * (`lab-session-2026-08-prereg-v2.md` §3.5) and they are the header of the `ground_truth.tsv`
 * artefact the same events are written to. One spelling, from the printed code through SQLite,
 * TSV, this endpoint and the analysis join — a translation layer here would be one more place for
 * `zone_id` to quietly become `zoneId` in half the corpus.
 *
 * Pure: no clock, no container, no I/O, so the parse rules are testable without booting a kernel.
 */
final class GroundTruthScanDto
{
    private function __construct(
        public readonly string $labSessionId,
        public readonly string $participantToken,
        public readonly string $zoneId,
        public readonly GroundTruthDirection $direction,
        public readonly string $site,
        public readonly int $monoNs,
        public readonly int $wallMs,
        public readonly string $scanNonce,
        public readonly ?string $recordingSessionId,
    ) {
    }

    /** Longest value any of these fields may carry; the columns are 128 wide. */
    private const MAX_FIELD = 128;

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException when the row is unusable — the caller rejects that one row
     *                                   and keeps the rest of the batch
     */
    public static function fromArray(array $payload): self
    {
        $direction = GroundTruthDirection::tryFromWire(self::stringOrNull($payload['direction'] ?? null));
        if ($direction === null) {
            // Resolved directions only. A row that reached the wire still saying "toggle" means
            // handset-side resolution did not run, and guessing here would need cross-device state
            // the server deliberately does not keep.
            throw new \InvalidArgumentException('direction must be "in" or "out"');
        }

        return new self(
            labSessionId: self::required($payload, 'lab_session_id'),
            participantToken: self::required($payload, 'participant_token'),
            zoneId: self::required($payload, 'zone_id'),
            direction: $direction,
            site: self::optional($payload, 'site'),
            monoNs: self::integer($payload, 'mono_ns'),
            wallMs: self::integer($payload, 'wall_ms'),
            scanNonce: self::required($payload, 'scan_nonce'),
            recordingSessionId: self::optional($payload, 'recording_session_id') ?: null,
        );
    }

    /** The triple the contradiction rule is defined over. */
    public function identityTriple(): string
    {
        return $this->participantToken . '|' . $this->zoneId . '|' . $this->direction->value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function required(array $payload, string $key): string
    {
        $value = self::stringOrNull($payload[$key] ?? null);
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException(sprintf('%s is required', $key));
        }
        if (strlen($value) > self::MAX_FIELD) {
            throw new \InvalidArgumentException(sprintf('%s exceeds %d characters', $key, self::MAX_FIELD));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function optional(array $payload, string $key): string
    {
        $value = self::stringOrNull($payload[$key] ?? null) ?? '';
        if (strlen($value) > self::MAX_FIELD) {
            throw new \InvalidArgumentException(sprintf('%s exceeds %d characters', $key, self::MAX_FIELD));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function integer(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        // JSON integers beyond 2^53 lose precision in some clients, so a numeric string is a
        // legitimate encoding for nanoseconds and is accepted as one.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException(sprintf('%s must be an integer', $key));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value);
        }

        return null;
    }
}
