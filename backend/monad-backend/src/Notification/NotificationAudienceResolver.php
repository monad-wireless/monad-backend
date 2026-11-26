<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Notification;
use App\Entity\PushToken;
use App\Entity\User;
use App\Enum\NotificationAudience;
use App\Enum\NotificationType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who a notification reaches (IP-157).
 *
 * Three audiences: `all` is every ACTIVE account (soft-deleted, inactive and banned ones are out,
 * because an anonymised row has nobody behind it and a banned one must not be written to);
 * `beta` is the subset with `users.cohort = 'beta'`; `operators` is the subset holding
 * ROLE_SUPERADMIN. Roles live in a json column with no DQL operator for "contains", so the
 * operator filter runs in PHP over the active set through the same `isSuperAdmin()` the
 * security layer reads. At beta scale that is a few hundred rows, not a problem.
 *
 * The push subset is a second, stricter filter on top: at least one unrevoked FCM token, and
 * the per-type opt-in (`notify_general` for general, `notify_callouts` for a quest callout, the
 * latter off by default under App Store Review Guideline 4.5.4). A user outside the push set
 * still gets the inbox row; that is the whole point of storing first.
 */
final class NotificationAudienceResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveFor(Notification $notification): Recipients
    {
        return $this->resolve($notification->getType(), $notification->getAudience());
    }

    public function resolve(NotificationType $type, NotificationAudience $audience): Recipients
    {
        $inbox = $this->inbox($audience);
        if ($inbox === []) {
            return new Recipients([], []);
        }

        $withToken = $this->userIdsWithActiveToken($inbox);
        $push = array_values(array_filter(
            $inbox,
            static fn (User $u): bool => isset($withToken[(string) $u->getId()?->toRfc4122()]) && self::optedIn($u, $type),
        ));

        return new Recipients($inbox, $push);
    }

    /**
     * Inbox and push-capable head counts, for the desk's estimate. Same rule as resolve(); it
     * only avoids handing the caller the user objects.
     *
     * @return array{inbox: int, push: int}
     */
    public function estimate(NotificationType $type, NotificationAudience $audience): array
    {
        $r = $this->resolve($type, $audience);

        return ['inbox' => count($r->inbox), 'push' => count($r->push)];
    }

    public static function optedIn(User $user, NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::GENERAL => $user->isNotifyGeneral(),
            NotificationType::QUEST_CALLOUT => $user->isNotifyCallouts(),
        };
    }

    /** @return list<User> */
    private function inbox(NotificationAudience $audience): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->andWhere('u.status = :active')->setParameter('active', UserStatus::ACTIVE)
            ->orderBy('u.email', 'ASC');

        if ($audience === NotificationAudience::BETA) {
            $qb->andWhere('u.cohort = :beta')->setParameter('beta', 'beta');
        }

        /** @var list<User> $users */
        $users = $qb->getQuery()->getResult();

        if ($audience === NotificationAudience::OPERATORS) {
            $users = array_values(array_filter($users, static fn (User $u): bool => $u->isSuperAdmin()));
        }

        return $users;
    }

    /**
     * @param list<User> $users
     * @return array<string, true> keyed by user id
     */
    private function userIdsWithActiveToken(array $users): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(t.user) AS uid')->from(PushToken::class, 't')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.user IN (:users)')->setParameter('users', $users)
            ->getQuery()->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['uid']] = true;
        }

        return $out;
    }
}
