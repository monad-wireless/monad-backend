<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;

/**
 * The resolved audience of one notification (IP-157): who gets an inbox row, and which of those
 * may also be pushed. `push` is always a subset of `inbox`.
 */
final readonly class Recipients
{
    /**
     * @param list<User> $inbox
     * @param list<User> $push
     */
    public function __construct(
        public array $inbox,
        public array $push,
    ) {
    }

    /** @return list<string> user ids (RFC 4122) of the push-capable subset */
    public function pushIds(): array
    {
        return array_values(array_map(static fn (User $u): string => (string) $u->getId()?->toRfc4122(), $this->push));
    }
}
