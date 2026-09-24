<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * What one dispatcher call did (IP-157). `outcome` is one of `sent` (deliveries written now),
 * `deferred` (scheduled for later, nothing written), `already_sent` (a second call, no-op).
 */
final readonly class DispatchResult
{
    public const SENT = 'sent';
    public const DEFERRED = 'deferred';
    public const ALREADY_SENT = 'already_sent';

    public function __construct(
        public string $outcome,
        public int $inbox = 0,
        public int $queued = 0,
        public int $skipped = 0,
        public int $messages = 0,
    ) {
    }

    public function isSent(): bool
    {
        return $this->outcome === self::SENT;
    }
}
