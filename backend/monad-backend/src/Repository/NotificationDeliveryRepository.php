<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\NotificationDelivery;
use App\Entity\User;
use App\Enum\PushStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationDelivery>
 */
class NotificationDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDelivery::class);
    }

    /**
     * Deliveries the push worker reported as FAILED.
     *
     * `skipped` is not counted and never will be: no token, no OS permission and an opt-in
     * that is off are decisions, not failures, and folding them together would put a permanent
     * non-zero on the Today page that nobody could ever clear.
     */
    public function countFailed(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.pushStatus = :failed')->setParameter('failed', PushStatus::FAILED)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * One user's inbox: sent, unexpired notifications, newest first, optionally only those sent
     * after an instant (the app's `?after=` cursor).
     *
     * @return list<NotificationDelivery>
     */
    public function findInbox(User $user, ?\DateTimeImmutable $after, \DateTimeImmutable $now, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('d')
            ->join('d.notification', 'n')->addSelect('n')
            ->andWhere('d.user = :user')->setParameter('user', $user)
            ->andWhere('n.sentAt IS NOT NULL')
            ->andWhere('n.expiresAt IS NULL OR n.expiresAt > :now')->setParameter('now', $now)
            ->orderBy('n.sentAt', 'DESC')
            ->setMaxResults($limit);
        if ($after !== null) {
            $qb->andWhere('n.sentAt > :after')->setParameter('after', $after);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneForUser(Notification $notification, User $user): ?NotificationDelivery
    {
        return $this->findOneBy(['notification' => $notification, 'user' => $user]);
    }

    /**
     * The desk's detail page: every delivery of one notification with its user, by email.
     *
     * @return list<NotificationDelivery>
     */
    public function findForNotification(Notification $notification): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.user', 'u')->addSelect('u')
            ->andWhere('d.notification = :n')->setParameter('n', $notification)
            ->orderBy('u.email', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * countsFor() for a whole log page in one query, keyed by notification id. A notification
     * with no deliveries (scheduled, unsent) is absent from the result; the caller fills zeros.
     *
     * @param list<Notification> $notifications
     * @return array<string, array{skipped: int, queued: int, sent: int, failed: int, read: int, total: int}>
     */
    public function countsForMany(array $notifications): array
    {
        if ($notifications === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.notification) AS nid, d.pushStatus AS status, COUNT(d.id) AS n, SUM(CASE WHEN d.readAt IS NOT NULL THEN 1 ELSE 0 END) AS r')
            ->andWhere('d.notification IN (:ns)')->setParameter('ns', $notifications)
            ->groupBy('nid, d.pushStatus')
            ->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $nid = (string) $row['nid'];
            $out[$nid] ??= ['skipped' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'read' => 0, 'total' => 0];
            $status = $row['status'] instanceof PushStatus ? $row['status']->value : (string) $row['status'];
            $out[$nid][$status] = (int) $row['n'];
            $out[$nid]['read'] += (int) $row['r'];
            $out[$nid]['total'] += (int) $row['n'];
        }

        return $out;
    }

    /**
     * The desk's counters for one notification: queued, sent, failed, skipped and read.
     *
     * @return array{skipped: int, queued: int, sent: int, failed: int, read: int, total: int}
     */
    public function countsFor(Notification $notification): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.pushStatus AS status, COUNT(d.id) AS n, SUM(CASE WHEN d.readAt IS NOT NULL THEN 1 ELSE 0 END) AS r')
            ->andWhere('d.notification = :n')->setParameter('n', $notification)
            ->groupBy('d.pushStatus')
            ->getQuery()->getArrayResult();

        $out = ['skipped' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'read' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof PushStatus ? $row['status']->value : (string) $row['status'];
            $out[$status] = (int) $row['n'];
            $out['read'] += (int) $row['r'];
            $out['total'] += (int) $row['n'];
        }

        return $out;
    }
}
