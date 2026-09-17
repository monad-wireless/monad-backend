<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Notification;
use App\Entity\NotificationDelivery;
use App\Entity\PushToken;
use App\Entity\User;
use App\Enum\PushStatus;
use App\Message\SendNotificationPush;
use App\Notification\PushMessage;
use App\Notification\PushSender;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\NotificationRepository;
use App\Repository\PushTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The worker side of a send (IP-157): one FCM call per recipient's token set, one outcome per
 * delivery row.
 *
 * Only `queued` deliveries are touched, so a redelivered message (Messenger retries up to
 * three times) neither double-sends nor overwrites a `sent`. A recipient whose every token was
 * revoked between the click and now is marked `failed` with the reason, not left `queued`.
 * Per recipient: any token accepted → `sent`; otherwise `failed` with the first error.
 */
#[AsMessageHandler]
final class SendNotificationPushHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly PushTokenRepository $tokens,
        private readonly PushSender $sender,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendNotificationPush $message): void
    {
        $notification = Uuid::isValid($message->notificationId)
            ? $this->notifications->find(Uuid::fromString($message->notificationId))
            : null;
        if (!$notification instanceof Notification) {
            // Cancelled (deleted) between dispatch and consumption: nothing to push, nothing to write.
            $this->logger->info('SendNotificationPush: notification gone, batch dropped', ['notification' => $message->notificationId]);

            return;
        }

        foreach ($message->userIds as $userId) {
            $delivery = $this->queuedDelivery($notification, $userId);
            if ($delivery === null) {
                continue;
            }
            $this->push($notification, $delivery);
        }

        $this->entityManager->flush();
    }

    private function queuedDelivery(Notification $notification, string $userId): ?NotificationDelivery
    {
        if (!Uuid::isValid($userId)) {
            return null;
        }
        $user = $this->entityManager->getRepository(User::class)->find(Uuid::fromString($userId));
        if (!$user instanceof User) {
            return null;
        }
        $delivery = $this->deliveries->findOneForUser($notification, $user);
        if (!$delivery instanceof NotificationDelivery || $delivery->getPushStatus() !== PushStatus::QUEUED) {
            return null;
        }

        return $delivery;
    }

    private function push(Notification $notification, NotificationDelivery $delivery): void
    {
        $tokens = array_map(static fn (PushToken $t): string => $t->getToken(), $this->tokens->findActiveForUser($delivery->getUser()));
        if ($tokens === []) {
            $delivery->markFailed('no active push token at send time');

            return;
        }

        $results = $this->sender->send(PushMessage::fromNotification($notification, $tokens));

        $firstError = null;
        foreach ($results as $result) {
            if ($result->status === PushStatus::SENT) {
                $delivery->markSent();

                return;
            }
            $firstError ??= $result->error;
        }
        $delivery->markFailed($firstError ?? 'push sender returned no result');
    }
}
