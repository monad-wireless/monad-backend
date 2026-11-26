<?php

namespace App\Repository;

use App\Entity\LabPlacement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LabPlacement>
 */
class LabPlacementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabPlacement::class);
    }

    /**
     * Every mirrored row of one floor, cards before nodes, keys in order.
     *
     * @return list<LabPlacement>
     */
    public function findByFloor(string $floor): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.floor = :floor')->setParameter('floor', $floor)
            ->orderBy('p.kind', 'ASC')->addOrderBy('p.key', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Replace-all for one floor: the semantics `lab_placements_write` promises. Runs inside the
     * caller's transaction so a failed import leaves the previous mirror in place.
     */
    public function deleteFloor(string $floor): int
    {
        return (int) $this->createQueryBuilder('p')
            ->delete()
            ->andWhere('p.floor = :floor')->setParameter('floor', $floor)
            ->getQuery()->execute();
    }

    /** The floors the mirror knows, for the board's floor switch. @return list<string> */
    public function floors(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.floor AS floor')
            ->orderBy('p.floor', 'ASC')
            ->getQuery()->getScalarResult();

        return array_map(static fn (array $r): string => (string) $r['floor'], $rows);
    }
}
