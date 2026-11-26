<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\NotificationDelivery;
use App\Entity\User;
use App\Enum\NotificationAudience;
use App\Enum\NotificationType;
use App\Enum\PushStatus;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\PushTokenRepository;
use App\Tests\Notification\NotificationDbTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-user notification surface over the real kernel, the jwt firewall and PostgreSQL
 * (IP-157). Requests go through `Kernel::handle()` directly: symfony/browser-kit is not
 * installed, and a WebTestCase client would only wrap the same call.
 *
 * Every shape asserted here is the wire contract the app lane fixed on 2026-09-16.
 */
final class MeNotificationsTest extends NotificationDbTestCase
{
    private User $me;
    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = $this->makeUser('me', cohort: 'beta');
        $this->jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->me);
    }

    /** @param array<string, mixed>|null $json */
    private function call(string $method, string $uri, ?array $json = null, ?string $jwt = null): Response
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        $bearer = $jwt ?? $this->jwt;
        if ($bearer !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        $request = Request::create($uri, $method, [], [], [], $server, $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR));

        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        $this->em->clear();

        return $response;
    }

    /** @return mixed */
    private static function body(Response $response): mixed
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function deliverTo(User $user, string $title, \DateTimeImmutable $sentAt, ?\DateTimeImmutable $expiresAt = null, NotificationType $type = NotificationType::GENERAL): NotificationDelivery
    {
        $n = $this->makeNotification($this->me, $type, NotificationAudience::BETA, $title);
        $n->markSent($sentAt);
        $n->setExpiresAt($expiresAt);
        $d = new NotificationDelivery($n, $user, PushStatus::SKIPPED);
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    // ── inbox ────────────────────────────────────────────────────────────────────────────────

    public function testInboxRequiresAToken(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->call('GET', '/api/me/notifications', jwt: '')->getStatusCode());
    }

    public function testInboxIsABareArrayOfTheContractShapeNewestFirst(): void
    {
        $older = $this->deliverTo($this->me, 'Older', new \DateTimeImmutable('2026-09-16T08:00:00+00:00'));
        $newer = $this->deliverTo($this->me, 'Newer', new \DateTimeImmutable('2026-09-16T09:30:00+00:00'), type: NotificationType::QUEST_CALLOUT);
        $newer->getNotification()->setDeepLink('https://monad.dubec.dev/m/MONAD-FP-07');
        $this->em->flush();
        // Not mine, not sent, expired: none of these may appear.
        $other = $this->makeUser('other', cohort: 'beta');
        $this->deliverTo($other, 'Theirs', new \DateTimeImmutable('2026-09-16T10:00:00+00:00'));
        $unsent = $this->makeNotification($this->me, title: 'Unsent');
        $this->em->persist(new NotificationDelivery($unsent, $this->me));
        $this->em->flush();
        $this->deliverTo($this->me, 'Expired', new \DateTimeImmutable('2026-09-16T07:00:00+00:00'), new \DateTimeImmutable('2026-09-16T07:30:00+00:00'));

        $response = $this->call('GET', '/api/me/notifications');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $rows = self::body($response);
        self::assertIsArray($rows);
        self::assertTrue(array_is_list($rows), 'a bare array, not an envelope');
        self::assertSame(['Newer', 'Older'], array_column($rows, 'title'));

        $row = $rows[0];
        self::assertSame(['id', 'type', 'title', 'body', 'quest_id', 'deep_link', 'sent_at', 'read_at'], array_keys($row));
        self::assertSame($newer->getNotification()->getId()->toRfc4122(), $row['id']);
        self::assertSame('quest_callout', $row['type']);
        self::assertNull($row['quest_id']);
        self::assertSame('https://monad.dubec.dev/m/MONAD-FP-07', $row['deep_link']);
        self::assertSame('2026-09-16T09:30:00+00:00', $row['sent_at'], 'ISO-8601 with an offset');
        self::assertNull($row['read_at']);
        self::assertSame('general', $rows[1]['type']);
        self::assertSame($older->getNotification()->getId()->toRfc4122(), $rows[1]['id']);
    }

    public function testAfterReturnsStrictlyLaterRowsOnly(): void
    {
        $this->deliverTo($this->me, 'At', new \DateTimeImmutable('2026-09-16T08:00:00+00:00'));
        $this->deliverTo($this->me, 'Before', new \DateTimeImmutable('2026-09-16T07:59:59+00:00'));
        $this->deliverTo($this->me, 'After', new \DateTimeImmutable('2026-09-16T08:00:01+00:00'));

        $rows = self::body($this->call('GET', '/api/me/notifications?after=2026-09-16T08:00:00Z'));

        self::assertSame(['After'], array_column($rows, 'title'));
    }

    public function testAfterMustParse(): void
    {
        $response = $this->call('GET', '/api/me/notifications?after=yesterday-ish');

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('VALIDATION_114', self::body($response)['code']);
    }

    // ── read ─────────────────────────────────────────────────────────────────────────────────

    public function testReadIsIdempotentAndScopedToMyInbox(): void
    {
        $mine = $this->deliverTo($this->me, 'Mine', new \DateTimeImmutable('2026-09-16T08:00:00+00:00'));
        $id = $mine->getNotification()->getId()->toRfc4122();
        $other = $this->makeUser('other', cohort: 'beta');
        $theirs = $this->deliverTo($other, 'Theirs', new \DateTimeImmutable('2026-09-16T08:00:00+00:00'));

        self::assertSame(Response::HTTP_NO_CONTENT, $this->call('POST', "/api/me/notifications/$id/read")->getStatusCode());
        $firstReadAt = self::body($this->call('GET', '/api/me/notifications'))[0]['read_at'];
        self::assertNotNull($firstReadAt);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->call('POST', "/api/me/notifications/$id/read", ['ignored' => true])->getStatusCode());
        self::assertSame($firstReadAt, self::body($this->call('GET', '/api/me/notifications'))[0]['read_at'], 'the first read wins');

        $theirId = $theirs->getNotification()->getId()->toRfc4122();
        self::assertSame(Response::HTTP_NOT_FOUND, $this->call('POST', "/api/me/notifications/$theirId/read")->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->call('POST', '/api/me/notifications/not-a-uuid/read')->getStatusCode());
        self::assertNull(static::getContainer()->get(NotificationDeliveryRepository::class)->findOneForUser($theirs->getNotification(), $other)?->getReadAt());
    }

    // ── push token ───────────────────────────────────────────────────────────────────────────

    public function testPushTokenUpsertReparentsBumpsAndUnrevokes(): void
    {
        $tokens = static::getContainer()->get(PushTokenRepository::class);

        $r = $this->call('PUT', '/api/me/push-token', ['token' => 'fcm-abc', 'platform' => 'android', 'handset_id' => 'inst-that-does-not-exist']);
        self::assertSame(Response::HTTP_NO_CONTENT, $r->getStatusCode());
        $row = $tokens->findByToken('fcm-abc');
        self::assertNotNull($row);
        self::assertSame($this->me->getId()->toRfc4122(), $row->getUser()->getId()->toRfc4122());
        self::assertSame('android', $row->getPlatform()->value);
        self::assertNull($row->getHandset(), 'an unknown installation id resolves to no handset');
        $firstSeen = $row->getLastSeenAt();

        // FCM says the phone is gone; then the same phone, now signed in as someone else, registers again.
        $row->revoke();
        $this->em->flush();
        $other = $this->makeUser('other');
        $otherJwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($other);
        self::assertSame(Response::HTTP_NO_CONTENT, $this->call('PUT', '/api/me/push-token', ['token' => 'fcm-abc', 'platform' => 'ios'], $otherJwt)->getStatusCode());

        $row = $tokens->findByToken('fcm-abc');
        self::assertSame($other->getId()->toRfc4122(), $row->getUser()->getId()->toRfc4122(), 'the newer registration wins the token');
        self::assertNull($row->getRevokedAt());
        self::assertGreaterThanOrEqual($firstSeen, $row->getLastSeenAt());
        self::assertCount(1, $tokens->findBy(['token' => 'fcm-abc']), 'upsert, not insert');
    }

    public function testPushTokenValidation(): void
    {
        self::assertSame('VALIDATION_111', self::body($this->call('PUT', '/api/me/push-token', ['platform' => 'ios']))['code']);
        self::assertSame('VALIDATION_111', self::body($this->call('PUT', '/api/me/push-token', ['token' => '   ', 'platform' => 'ios']))['code']);
        self::assertSame('VALIDATION_112', self::body($this->call('PUT', '/api/me/push-token', ['token' => 'x', 'platform' => 'windows']))['code']);
        self::assertSame('VALIDATION_110', self::body($this->call('PUT', '/api/me/push-token', ['a', 'b']))['code']);
        $r = $this->call('PUT', '/api/me/push-token', ['token' => 'x']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $r->getStatusCode());
        self::assertSame('VALIDATION_112', self::body($r)['code']);
    }

    public function testPushTokenDeleteIs204WhetherOrNotItExistsAndOnlyRemovesMine(): void
    {
        $tokens = static::getContainer()->get(PushTokenRepository::class);
        $this->call('PUT', '/api/me/push-token', ['token' => 'fcm-mine', 'platform' => 'ios']);
        $other = $this->makeUser('other');
        $this->makeToken($other, 'fcm-theirs');

        self::assertSame(Response::HTTP_NO_CONTENT, $this->call('DELETE', '/api/me/push-token/fcm-mine')->getStatusCode());
        self::assertNull($tokens->findByToken('fcm-mine'));
        self::assertSame(Response::HTTP_NO_CONTENT, $this->call('DELETE', '/api/me/push-token/fcm-mine')->getStatusCode(), 'already gone is still 204');
        self::assertSame(Response::HTTP_NO_CONTENT, $this->call('DELETE', '/api/me/push-token/fcm-theirs')->getStatusCode());
        self::assertNotNull($tokens->findByToken('fcm-theirs'), 'another account\'s token is left alone');
        self::assertSame(Response::HTTP_NO_CONTENT, $this->call('DELETE', '/api/me/push-token/never-seen')->getStatusCode());
    }

    // ── preferences ──────────────────────────────────────────────────────────────────────────

    public function testPreferencesRoundTrip(): void
    {
        $get = $this->call('GET', '/api/me/notification-preferences');
        self::assertSame(Response::HTTP_OK, $get->getStatusCode());
        self::assertSame(['notify_general' => true, 'notify_callouts' => false], self::body($get), 'defaults: general on, callouts off');

        $put = $this->call('PUT', '/api/me/notification-preferences', ['notify_general' => false, 'notify_callouts' => true]);
        self::assertSame(Response::HTTP_OK, $put->getStatusCode());
        self::assertSame(['notify_general' => false, 'notify_callouts' => true], self::body($put), 'the PUT answers with the same shape');
        self::assertSame(['notify_general' => false, 'notify_callouts' => true], self::body($this->call('GET', '/api/me/notification-preferences')));

        $fresh = $this->em->getRepository(User::class)->find($this->me->getId());
        self::assertFalse($fresh->isNotifyGeneral());
        self::assertTrue($fresh->isNotifyCallouts());
    }

    public function testPreferencesMustBeTwoBooleans(): void
    {
        $r = $this->call('PUT', '/api/me/notification-preferences', ['notify_general' => 'yes', 'notify_callouts' => true]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $r->getStatusCode());
        self::assertSame('VALIDATION_113', self::body($r)['code']);

        $r = $this->call('PUT', '/api/me/notification-preferences', ['notify_general' => true]);
        self::assertSame('VALIDATION_113', self::body($r)['code']);
        self::assertSame(['notify_general' => true, 'notify_callouts' => false], self::body($this->call('GET', '/api/me/notification-preferences')), 'nothing changed');
    }
}
