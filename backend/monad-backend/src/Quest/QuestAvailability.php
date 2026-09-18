<?php

namespace App\Quest;

/**
 * Whether a quest can be started here, now, by this participant (IP-128).
 *
 * A closed reason vocabulary rather than free text, because both the app and the
 * public page branch on it and a typo would silently become an unreachable UI
 * state.
 */
final class QuestAvailability implements \JsonSerializable
{
    public const REASON_COOLDOWN = 'cooldown';
    public const REASON_IN_PROGRESS = 'in_progress';
    public const REASON_WINDOW_CLOSED = 'window_closed';
    public const REASON_NOT_ARMED = 'not_armed';
    public const REASON_DEVICE_INACTIVE = 'device_inactive';
    /** The node is powered and healthy but not capturing — see QuestArmingService. */
    public const REASON_NODE_IDLE = 'node_idle';

    private function __construct(
        public readonly bool $available,
        public readonly ?string $reason = null,
        public readonly ?\DateTimeImmutable $retryAt = null,
    ) {
    }

    public static function available(): self
    {
        return new self(true);
    }

    public static function blocked(string $reason, ?\DateTimeImmutable $retryAt = null): self
    {
        return new self(false, $reason, $retryAt);
    }

    /** @return array{available: bool, reason: string|null, retry_at: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'available' => $this->available,
            'reason' => $this->reason,
            'retry_at' => $this->retryAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
