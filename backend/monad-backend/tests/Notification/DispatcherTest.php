<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\NotificationDelivery;
use App\Enum\NotificationAudience;
use App\Enum\NotificationType;
use App\Enum\PushStatus;
use App\Message\SendNotificationPush;
use App\MessageHandler\SendNotificationPushHandler;
use App\Notification\DispatchResult;
use App\Notification\NotificationAudienceResolver;
use App\Notification\NotificationDispatcher;
use App\Notification\PushMessage;
use App\Notification\PushResult;
use App\Notification\PushSender;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\NotificationRepository;
use App\Repository\PushTokenRepository;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The dispatcher and the worker handler (IP-157) against the real database and the in-memory
 * `async` transport the test env configures. The push transport is a fake so no socket opens.
 */
final class DispatcherTest extends NotificationDbTestCase
{
    private NotificationDeliveryRepository $deliveries;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deliveries = static::getContainer()->get(NotificationDeliveryRepository::class);
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport, 'tests run on the in-memory transport');
        $this->transport = $transport;
        $this->transport->reset();
    }

    /**
     * A PushSender that answers per token from a map (default: sent) and records every call
     * on its public `$calls` list.
     *
     * @param array<string, PushResult> $byToken
     */
    private function fakeSender(bool $configured, array $byToken = []): PushSender
    {
        return new class($configured, $byToken) implements PushSender {
            /** @var list<PushMessage> */
            public array $calls = [];

            public function __construct(private readonly bool $configured, private readonly array $byToken)
            {
            }

            public function send(PushMessage $message): array
            {
                $this->calls[] = $message;

                return array_map(fn (string $t): PushResult => $this->byToken[$t] ?? PushResult::sent($t), $message->tokens);
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function describe(): string
            {
                return 'fake';
            }
        };
    }

    private function dispatcher(PushSender $sender): NotificationDispatcher
    {
        return new NotificationDispatcher(
            static::getContainer()->get(NotificationAudienceResolver::class),
            $sender,
            $this->em,
            static::getContainer()->get(MessageBusInterface::class),
        );
    }

    public function testSendWritesOneDeliveryPerRecipientAndQueuesThePushCapable(): void
    {
        $author = $this->makeUser('author', admin: true);
        $withToken = $this->makeUser('with-token', cohort: 'beta');
        $this->makeToken($withToken, 'tok-1');
        $optedOut = $this->makeUser('opted-out', cohort: 'beta', notifyGeneral: false);
        $this->makeToken($optedOut, 'tok-2');
        $noToken = $this->makeUser('no-token', cohort: 'beta');
        $n = $this->makeNotification($author, audience: NotificationAudience::BETA);

        $result = $this->dispatcher($this->fakeSender(true))->send($n, new \DateTimeImmutable('2026-09-16 12:00:00+00:00'));

        self::assertSame(DispatchResult::SENT, $result->outcome);
        self::assertGreaterThanOrEqual(3, $result->inbox);
        self::assertSame(1, $result->queued, 'only the opted-in user with a live token');
        self::assertSame(1, $result->messages);
        self::assertSame('2026-09-16T12:00:00+00:00', $n->getSentAt()?->format(\DateTimeInterface::ATOM));

        $this->em->clear();
        $reloaded = static::getContainer()->get(NotificationRepository::class)->find($n->getId());
        $byUser = [];
        foreach ($this->deliveries->findForNotification($reloaded) as $d) {
            $byUser[$d->getUser()->getId()->toRfc4122()] = $d->getPushStatus();
        }
        self::assertSame(PushStatus::QUEUED, $byUser[$withToken->getId()->toRfc4122()]);
        self::assertSame(PushStatus::SKIPPED, $byUser[$optedOut->getId()->toRfc4122()]);
        self::assertSame(PushStatus::SKIPPED, $byUser[$noToken->getId()->toRfc4122()]);

        $sent = $this->transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendNotificationPush::class, $message);
        self::assertSame($n->getId()->toRfc4122(), $message->notificationId);
        self::assertSame([$withToken->getId()->toRfc4122()], $message->userIds);
    }

    public function testSecondSendIsANoOp(): void
    {
        $author = $this->makeUser('author', admin: true);
        $this->makeUser('beta', cohort: 'beta');
        $n = $this->makeNotification($author);
        $dispatcher = $this->dispatcher($this->fakeSender(true));

        $first = $dispatcher->send($n);
        $countAfterFirst = count($this->deliveries->findForNotification($n));
        $second = $dispatcher->send($n);

        self::assertSame(DispatchResult::SENT, $first->outcome);
        self::assertSame(DispatchResult::ALREADY_SENT, $second->outcome);
        self::assertSame(0, $second->inbox);
        self::assertCount($countAfterFirst, $this->deliveries->findForNotification($n));
        self::assertCount(0, $this->transport->getSent(), 'nobody had a token, so no push message either time');
    }

    public function testScheduledForTheFutureIsLeftUnsent(): void
    {
        $author = $this->makeUser('author', admin: true);
        $this->makeUser('beta', cohort: 'beta');
        $n = $this->makeNotification($author);
        $n->setScheduledFor(new \DateTimeImmutable('+2 hours'));
        $this->em->flush();

        $result = $this->dispatcher($this->fakeSender(true))->send($n);

        self::assertSame(DispatchResult::DEFERRED, $result->outcome);
        self::assertNull($n->getSentAt());
        self::assertCount(0, $this->deliveries->findForNotification($n));
        self::assertCount(0, $this->transport->getSent());
    }

    public function testScheduledInThePastIsDueAndSends(): void
    {
        $author = $this->makeUser('author', admin: true);
        $this->makeUser('beta', cohort: 'beta');
        $n = $this->makeNotification($author);
        $n->setScheduledFor(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $due = static::getContainer()->get(NotificationRepository::class)->findDue(new \DateTimeImmutable());
        self::assertContains($n->getId()->toRfc4122(), array_map(static fn ($x) => $x->getId()->toRfc4122(), $due));

        self::assertSame(DispatchResult::SENT, $this->dispatcher($this->fakeSender(true))->send($n)->outcome);
        self::assertNotNull($n->getSentAt());
        self::assertSame([], static::getContainer()->get(NotificationRepository::class)->findDue(new \DateTimeImmutable()), 'sent rows are no longer due');
    }

    public function testNullSenderSkipsEveryPushInsteadOfQueueing(): void
    {
        $author = $this->makeUser('author', admin: true);
        $u = $this->makeUser('beta', cohort: 'beta');
        $this->makeToken($u, 'tok-1');
        $n = $this->makeNotification($author);

        $result = $this->dispatcher($this->fakeSender(false))->send($n);

        self::assertSame(0, $result->queued);
        self::assertGreaterThanOrEqual(1, $result->skipped);
        self::assertCount(0, $this->transport->getSent());
        foreach ($this->deliveries->findForNotification($n) as $d) {
            self::assertSame(PushStatus::SKIPPED, $d->getPushStatus());
        }
    }

    public function testPushOffSkipsEveryoneEvenWhenConfigured(): void
    {
        $author = $this->makeUser('author', admin: true);
        $u = $this->makeUser('beta', cohort: 'beta');
        $this->makeToken($u, 'tok-1');
        $n = $this->makeNotification($author)->setPush(false);
        $this->em->flush();

        $result = $this->dispatcher($this->fakeSender(true))->send($n);

        self::assertSame(0, $result->queued);
        self::assertCount(0, $this->transport->getSent());
    }

    public function testHandlerWritesSentAndFailedPerDeliveryAndOnlyTouchesQueuedRows(): void
    {
        $author = $this->makeUser('author', admin: true);
        $ok = $this->makeUser('ok', cohort: 'beta');
        $this->makeToken($ok, 'tok-ok-dead');
        $this->makeToken($ok, 'tok-ok-live');
        $bad = $this->makeUser('bad', cohort: 'beta');
        $this->makeToken($bad, 'tok-bad');
        $n = $this->makeNotification($author, type: NotificationType::QUEST_CALLOUT);
        foreach ([$ok, $bad] as $u) {
            $u->setNotifyCallouts(true);
        }
        $this->em->flush();

        $dispatcher = $this->dispatcher($this->fakeSender(true));
        $result = $dispatcher->send($n);
        self::assertSame(2, $result->queued);
        $message = $this->transport->getSent()[0]->getMessage();
        self::assertInstanceOf(SendNotificationPush::class, $message);

        $sender = $this->fakeSender(true, [
            'tok-ok-dead' => PushResult::failed('tok-ok-dead', 'NotFound: unregistered', tokenRevoked: true),
            'tok-bad' => PushResult::failed('tok-bad', 'InvalidMessage: The registration token is not a valid FCM registration token'),
        ]);
        $handler = new SendNotificationPushHandler(
            static::getContainer()->get(NotificationRepository::class),
            $this->deliveries,
            static::getContainer()->get(PushTokenRepository::class),
            $sender,
            $this->em,
            new NullLogger(),
        );

        $handler($message);
        // A redelivery (Messenger retry) finds no queued row and sends nothing more.
        $handler($message);

        self::assertCount(2, $sender->calls, 'one send per queued recipient, none on the retry');
        self::assertSame($n->getId()->toRfc4122(), $sender->calls[0]->notificationId);
        self::assertSame(NotificationType::QUEST_CALLOUT, $sender->calls[0]->type);

        $this->em->clear();
        $reloaded = static::getContainer()->get(NotificationRepository::class)->find($n->getId());
        $byUser = [];
        foreach ($this->deliveries->findForNotification($reloaded) as $d) {
            $byUser[$d->getUser()->getId()->toRfc4122()] = $d;
        }
        $okRow = $byUser[$ok->getId()->toRfc4122()];
        $badRow = $byUser[$bad->getId()->toRfc4122()];
        self::assertInstanceOf(NotificationDelivery::class, $okRow);
        self::assertSame(PushStatus::SENT, $okRow->getPushStatus(), 'one accepted token is a sent delivery');
        self::assertNotNull($okRow->getDeliveredAt());
        self::assertNull($okRow->getPushError());
        self::assertSame(PushStatus::FAILED, $badRow->getPushStatus());
        self::assertNull($badRow->getDeliveredAt());
        self::assertStringContainsString('not a valid FCM registration token', (string) $badRow->getPushError());
    }

    public function testHandlerFailsARecipientWhoseTokensWereRevokedMeanwhile(): void
    {
        $author = $this->makeUser('author', admin: true);
        $u = $this->makeUser('u', cohort: 'beta');
        $token = $this->makeToken($u, 'tok-1');
        $n = $this->makeNotification($author);
        $this->dispatcher($this->fakeSender(true))->send($n);
        $message = $this->transport->getSent()[0]->getMessage();

        $token->revoke();
        $this->em->flush();

        $handler = new SendNotificationPushHandler(
            static::getContainer()->get(NotificationRepository::class),
            $this->deliveries,
            static::getContainer()->get(PushTokenRepository::class),
            $this->fakeSender(true),
            $this->em,
            new NullLogger(),
        );
        $handler($message);

        $row = $this->deliveries->findOneForUser($n, $u);
        self::assertSame(PushStatus::FAILED, $row?->getPushStatus());
        self::assertSame('no active push token at send time', $row?->getPushError());
    }

    public function testHandlerDropsABatchWhoseNotificationWasCancelled(): void
    {
        $handler = new SendNotificationPushHandler(
            static::getContainer()->get(NotificationRepository::class),
            $this->deliveries,
            static::getContainer()->get(PushTokenRepository::class),
            $this->fakeSender(true),
            $this->em,
            new NullLogger(),
        );

        $handler(new SendNotificationPush('00000000-0000-4000-8000-000000000000', ['00000000-0000-4000-8000-000000000001']));

        $this->addToAssertionCount(1); // no exception is the assertion
    }
}
