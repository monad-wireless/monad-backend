<?php

declare(strict_types=1);

namespace App\Notification;

use App\Repository\PushTokenRepository;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\SendReport;
use Psr\Log\LoggerInterface;

/**
 * FCM HTTP v1 through kreait/firebase-php (IP-157).
 *
 * One CloudMessage per token, sent as a batch (`sendAll`), one PushResult per token from the
 * report. A token FCM reports as unregistered (404 NotFound, or a sender-id mismatch) or as
 * malformed ("not a valid FCM registration token") is REVOKED here, on the PushToken row, so
 * the next audience resolution stops counting that phone as push-capable. The revocation is
 * persisted by the caller's flush: this class never flushes, because it runs inside the
 * message handler's unit of work.
 */
final class FcmPushSender implements PushSender
{
    private const ERROR_MAX = 500;

    public function __construct(
        private readonly Messaging $messaging,
        private readonly FcmPayloadBuilder $payloads,
        private readonly PushTokenRepository $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(PushMessage $message): array
    {
        if ($message->tokens === []) {
            return [];
        }

        $messages = [];
        foreach ($message->tokens as $token) {
            $messages[] = $this->payloads->build($message, $token);
        }

        try {
            $report = $this->messaging->sendAll($messages);
        } catch (\Throwable $e) {
            // The whole batch failed before FCM answered per token (auth, network, quota).
            $error = self::trim($e->getMessage());
            $this->logger->error('FCM batch send failed', ['notification' => $message->notificationId, 'error' => $error]);

            return array_map(static fn (string $t): PushResult => PushResult::failed($t, $error), $message->tokens);
        }

        // Results keyed by token: the report is in send order, but keying is what the handler
        // needs and it costs nothing.
        $byToken = [];
        foreach ($report->getItems() as $item) {
            $byToken[$item->target()->value()] = $item;
        }

        $results = [];
        foreach ($message->tokens as $token) {
            $item = $byToken[$token] ?? null;
            if (!$item instanceof SendReport) {
                $results[] = PushResult::failed($token, 'FCM returned no result for this token');
                continue;
            }
            if ($item->isSuccess()) {
                $results[] = PushResult::sent($token);
                continue;
            }

            $error = self::trim($item->error()?->getMessage() ?? 'unknown FCM error');
            $revoke = $item->messageWasSentToUnknownToken() || $item->messageTargetWasInvalid();
            if ($revoke) {
                $this->tokens->findByToken($token)?->revoke();
                $this->logger->info('Push token revoked by FCM verdict', ['notification' => $message->notificationId]);
            }
            $results[] = PushResult::failed($token, $error, $revoke);
        }

        return $results;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'Push goes out through FCM HTTP v1 (kreait/firebase-php) to every opted-in recipient with a registered token.';
    }

    private static function trim(string $message): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($line) > self::ERROR_MAX ? mb_substr($line, 0, self::ERROR_MAX) . '…' : $line;
    }
}
