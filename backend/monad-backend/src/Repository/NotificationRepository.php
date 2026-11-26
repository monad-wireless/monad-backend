<?php

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Scheduled notifications whose instant has passed and that were never sent: what
     * app:notifications:dispatch-due picks up.
     *
     * @return list<Notification>
     */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.sentAt IS NULL')
            ->andWhere('n.scheduledFor IS NOT NULL')
            ->andWhere('n.scheduledFor <= :now')->setParameter('now', $now)
            ->orderBy('n.scheduledFor', 'ASC')
            ->getQuery()->getResult();
    }

    /** The desk's log, newest first. @return list<Notification> */
    public function findLog(int $limit = 100): array
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
