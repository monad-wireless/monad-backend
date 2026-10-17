<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;

/**
 * The adapter when MONAD_FCM_CREDENTIALS is empty or names no readable file (IP-157).
 *
 * The inbox works without it; only the push is skipped. One log line per skipped send so an
 * operator reading `docker logs` sees why a phone stayed silent, and `describe()` tells the desk
 * the same in one sentence. Not an error state: a deployment without a Firebase project is the
 * documented default.
 */
final class NullPushSender implements PushSender
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $reason = 'MONAD_FCM_CREDENTIALS is empty',
    ) {
    }

    public function send(PushMessage $message): array
    {
        $this->logger->info('Push skipped: no FCM adapter', [
            'notification' => $message->notificationId,
            'tokens' => count($message->tokens),
            'reason' => $this->reason,
        ]);

        return array_map(fn (string $t): PushResult => PushResult::skipped($t, $this->reason), $message->tokens);
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return sprintf('Push is off: %s. Every recipient still gets the inbox row.', $this->reason);
    }
}
