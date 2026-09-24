<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Marker for messages that travel over the `async` Doctrine transport (config/packages/messenger.yaml).
 *
 * Messenger routing needs a class or interface that exists at container compile time, and Phase 4
 * of IP-157 defines the first concrete message (SendNotification). Implementing this interface
 * is the whole opt-in: a message class without it is handled synchronously in the request.
 */
interface AsyncMessage
{
}
