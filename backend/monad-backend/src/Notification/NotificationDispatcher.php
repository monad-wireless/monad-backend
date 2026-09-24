<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Notification;
use App\Entity\NotificationDelivery;
use App\Enum\PushStatus;
use App\Message\SendNotificationPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turns a composed notification into deliveries and push work (IP-157).
 *
 * `send()` does, in one unit of work: resolve the audience, write one NotificationDelivery per
 * inbox recipient (`queued` when a push will be attempted, `skipped` otherwise), stamp
 * `sent_at`. Then, after the flush, it dispatches one SendNotificationPush per batch of queued
 * recipients over the Doctrine transport, so the composer's click returns as soon as the rows
 * are written and the FCM calls run in the worker.
 *
 * Idempotent by construction: a notification with `sent_at` set is a no-op (the entity throws
 * on a second markSent, and this class checks first), and the UNIQUE (notification_id, user_id)
 * constraint would refuse a duplicate delivery even if it did not. A notification scheduled for
 * a future instant is left untouched; app:notifications:dispatch-due calls back when it is due.
 *
 * Push is skipped for everyone, not queued, when the sender is the null adapter: a queued row
 * that no worker can ever advance would read as "in flight" forever on the desk.
 */
final class NotificationDispatcher
{
    /** Recipients per Messenger message. FCM's own batch limit is 500; this keeps a retry small. */
    public const BATCH = 100;

    public function __construct(
        private readonly NotificationAudienceResolver $audience,
        private readonly PushSender $pushSender,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function send(Notification $notification, ?\DateTimeImmutable $now = null): DispatchResult
    {
        $now ??= new \DateTimeImmutable();

        if ($notification->getSentAt() !== null) {
            return new DispatchResult(DispatchResult::ALREADY_SENT);
        }
        $scheduled = $notification->getScheduledFor();
        if ($scheduled !== null && $scheduled > $now) {
            return new DispatchResult(DispatchResult::DEFERRED);
        }

        $recipients = $this->audience->resolveFor($notification);
        $pushIds = $notification->isPush() && $this->pushSender->isConfigured()
            ? array_fill_keys($recipients->pushIds(), true)
            : [];

        $queued = [];
        $skipped = 0;
        foreach ($recipients->inbox as $user) {
            $uid = (string) $user->getId()?->toRfc4122();
            $status = isset($pushIds[$uid]) ? PushStatus::QUEUED : PushStatus::SKIPPED;
            $this->entityManager->persist(new NotificationDelivery($notification, $user, $status));
            if ($status === PushStatus::QUEUED) {
                $queued[] = $uid;
            } else {
                ++$skipped;
            }
        }

        $notification->markSent($now);
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        $messages = 0;
        foreach (array_chunk($queued, self::BATCH) as $batch) {
            $this->bus->dispatch(new SendNotificationPush($notification->getId()->toRfc4122(), $batch));
            ++$messages;
        }

        return new DispatchResult(DispatchResult::SENT, count($recipients->inbox), count($queued), $skipped, $messages);
    }
}
