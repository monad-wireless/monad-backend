<?php

declare(strict_types=1);

namespace App\Message;

/**
 * "Push this notification to these recipients" (IP-157), one per batch of queued deliveries.
 *
 * Carries ids, never entities: the worker loads fresh rows, so a token revoked or a preference
 * flipped between the click and the send is honoured. Implements AsyncMessage, which is the
 * whole routing rule (messenger.yaml sends it to the `async` Doctrine transport).
 */
final readonly class SendNotificationPush implements AsyncMessage
{
    /**
     * @param list<string> $userIds RFC 4122 ids of users whose delivery is `queued`
     */
    public function __construct(
        public string $notificationId,
        public array $userIds,
    ) {
    }
}
