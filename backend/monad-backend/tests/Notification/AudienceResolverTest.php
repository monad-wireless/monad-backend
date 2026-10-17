<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Enum\NotificationAudience;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use App\Notification\NotificationAudienceResolver;

/**
 * Who a notification reaches (IP-157), against the real users table. Assertions are on
 * membership, never on exact counts: the test database is shared with other suites that
 * create users of their own.
 */
final class AudienceResolverTest extends NotificationDbTestCase
{
    private NotificationAudienceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = static::getContainer()->get(NotificationAudienceResolver::class);
    }

    public function testAllIsEveryActiveAccountAndNobodyElse(): void
    {
        $active = $this->makeUser('active');
        $beta = $this->makeUser('beta', cohort: 'beta');
        $admin = $this->makeUser('admin', admin: true);
        $deleted = $this->makeUser('deleted');
        $deleted->softDelete();
        $inactive = $this->makeUser('inactive', status: UserStatus::INACTIVE);
        $banned = $this->makeUser('banned', status: UserStatus::BANNED);
        $this->em->flush();

        $ids = self::ids($this->resolver->resolve(NotificationType::GENERAL, NotificationAudience::ALL)->inbox);

        self::assertContains($active->getId()->toRfc4122(), $ids);
        self::assertContains($beta->getId()->toRfc4122(), $ids);
        self::assertContains($admin->getId()->toRfc4122(), $ids);
        self::assertNotContains($deleted->getId()->toRfc4122(), $ids, 'a soft-deleted account has nobody behind it');
        self::assertNotContains($inactive->getId()->toRfc4122(), $ids);
        self::assertNotContains($banned->getId()->toRfc4122(), $ids);
    }

    public function testBetaIsTheCohortOnly(): void
    {
        $plain = $this->makeUser('plain');
        $beta = $this->makeUser('beta', cohort: 'beta');
        $betaGone = $this->makeUser('beta-deleted', cohort: 'beta');
        $betaGone->softDelete();
        $this->em->flush();

        $ids = self::ids($this->resolver->resolve(NotificationType::GENERAL, NotificationAudience::BETA)->inbox);

        self::assertContains($beta->getId()->toRfc4122(), $ids);
        self::assertNotContains($plain->getId()->toRfc4122(), $ids);
        self::assertNotContains($betaGone->getId()->toRfc4122(), $ids);
    }

    public function testOperatorsHoldRoleSuperadmin(): void
    {
        $plain = $this->makeUser('plain');
        $admin = $this->makeUser('admin', admin: true);
        $betaAdmin = $this->makeUser('beta-admin', cohort: 'beta', admin: true);

        $ids = self::ids($this->resolver->resolve(NotificationType::GENERAL, NotificationAudience::OPERATORS)->inbox);

        self::assertContains($admin->getId()->toRfc4122(), $ids);
        self::assertContains($betaAdmin->getId()->toRfc4122(), $ids);
        self::assertNotContains($plain->getId()->toRfc4122(), $ids);
    }

    public function testPushNeedsAnUnrevokedTokenAndTheTypeOptIn(): void
    {
        $noToken = $this->makeUser('no-token', cohort: 'beta');
        $revokedOnly = $this->makeUser('revoked', cohort: 'beta');
        $this->makeToken($revokedOnly, 'tok-revoked', revoked: true);
        $generalOnly = $this->makeUser('general-only', cohort: 'beta', notifyGeneral: true, notifyCallouts: false);
        $this->makeToken($generalOnly, 'tok-general');
        $calloutsOnly = $this->makeUser('callouts-only', cohort: 'beta', notifyGeneral: false, notifyCallouts: true);
        $this->makeToken($calloutsOnly, 'tok-callouts');
        $both = $this->makeUser('both', cohort: 'beta', notifyGeneral: true, notifyCallouts: true);
        $this->makeToken($both, 'tok-both-revoked', revoked: true);
        $this->makeToken($both, 'tok-both-live');

        $general = $this->resolver->resolve(NotificationType::GENERAL, NotificationAudience::BETA);
        $callout = $this->resolver->resolve(NotificationType::QUEST_CALLOUT, NotificationAudience::BETA);

        // Everyone is in the inbox regardless of tokens or opt-ins.
        foreach ([$noToken, $revokedOnly, $generalOnly, $calloutsOnly, $both] as $u) {
            self::assertContains($u->getId()->toRfc4122(), self::ids($general->inbox));
            self::assertContains($u->getId()->toRfc4122(), self::ids($callout->inbox));
        }

        $generalPush = self::ids($general->push);
        self::assertContains($generalOnly->getId()->toRfc4122(), $generalPush);
        self::assertContains($both->getId()->toRfc4122(), $generalPush, 'one live token among revoked ones is enough');
        self::assertNotContains($calloutsOnly->getId()->toRfc4122(), $generalPush, 'notify_general off');
        self::assertNotContains($noToken->getId()->toRfc4122(), $generalPush);
        self::assertNotContains($revokedOnly->getId()->toRfc4122(), $generalPush, 'a revoked token is no token');

        $calloutPush = self::ids($callout->push);
        self::assertContains($calloutsOnly->getId()->toRfc4122(), $calloutPush);
        self::assertContains($both->getId()->toRfc4122(), $calloutPush);
        self::assertNotContains($generalOnly->getId()->toRfc4122(), $calloutPush, 'callouts are opt-in (default off)');
        self::assertNotContains($noToken->getId()->toRfc4122(), $calloutPush);
    }

    public function testDefaultsAreGeneralOnCalloutsOff(): void
    {
        $fresh = $this->makeUser('fresh', cohort: 'beta');
        $this->makeToken($fresh, 'tok-fresh');

        self::assertContains($fresh->getId()->toRfc4122(), self::ids($this->resolver->resolve(NotificationType::GENERAL, NotificationAudience::BETA)->push));
        self::assertNotContains($fresh->getId()->toRfc4122(), self::ids($this->resolver->resolve(NotificationType::QUEST_CALLOUT, NotificationAudience::BETA)->push));
    }

    public function testEstimateMatchesResolve(): void
    {
        $a = $this->makeUser('a', cohort: 'beta');
        $this->makeToken($a, 'tok-a');
        $this->makeUser('b', cohort: 'beta');

        $r = $this->resolver->resolve(NotificationType::GENERAL, NotificationAudience::BETA);
        $e = $this->resolver->estimate(NotificationType::GENERAL, NotificationAudience::BETA);

        self::assertSame(['inbox' => count($r->inbox), 'push' => count($r->push)], $e);
        self::assertGreaterThanOrEqual(2, $e['inbox']);
        self::assertGreaterThanOrEqual(1, $e['push']);
    }
}
