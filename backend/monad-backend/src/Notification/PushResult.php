<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\PushStatus;

/** The outcome for one registration token (IP-157). */
final readonly class PushResult
{
    public function __construct(
        public string $token,
        public PushStatus $status,
        public ?string $error = null,
        /** True when FCM said the token is unregistered or malformed and the sender revoked it. */
        public bool $tokenRevoked = false,
    ) {
    }

    public static function sent(string $token): self
    {
        return new self($token, PushStatus::SENT);
    }

    public static function failed(string $token, string $error, bool $tokenRevoked = false): self
    {
        return new self($token, PushStatus::FAILED, $error, $tokenRevoked);
    }

    public static function skipped(string $token, string $reason): self
    {
        return new self($token, PushStatus::SKIPPED, $reason);
    }
}
