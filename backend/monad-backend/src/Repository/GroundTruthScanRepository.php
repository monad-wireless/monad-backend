<?php

namespace App\Repository;

use App\Entity\GroundTruthScan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroundTruthScan>
 */
class GroundTruthScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroundTruthScan::class);
    }

    public function findOneByNonce(string $scanNonce): ?GroundTruthScan
    {
        return $this->findOneBy(['scanNonce' => $scanNonce]);
    }

    /**
     * Every scan of one session, oldest first on the join clock.
     *
     * The whole session, not a window: a lab session is 10–12 participants over three zones, so a
     * few hundred rows at the very end of the longest day. Folding that in PHP is microseconds and
     * keeps the occupancy rule — which is a per-participant "latest wins", not a plain sum — in one
     * readable, unit-testable place instead of spread across a window function. If a session ever
     * grew by two orders of magnitude this is the query to revisit; at this scale a cursor would be
     * complexity bought with no gain.
     *
     * @return GroundTruthScan[]
     */
    public function findForSession(string $labSessionId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.labSessionId = :labSessionId')
            ->setParameter('labSessionId', $labSessionId)
            ->orderBy('s.monoNs', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
