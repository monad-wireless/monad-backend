<?php

namespace App\Repository;

use App\Entity\GroundTruthConflict;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroundTruthConflict>
 */
class GroundTruthConflictRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroundTruthConflict::class);
    }

    /**
     * E3 contradictions observed since an instant.
     *
     * The Today page asks for the last seven days, because a conflict is only actionable while
     * the people who produced it are still findable; the row itself is never reconciled (see
     * the entity), so this is a count of things to LOOK at, not of things to fix.
     */
    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.observedAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * @return GroundTruthConflict[]
     */
    public function findForSession(string $labSessionId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.labSessionId = :labSessionId')
            ->setParameter('labSessionId', $labSessionId)
            ->orderBy('c.observedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
