<?php

namespace App\Repository;

use App\Entity\Handset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Handset>
 */
class HandsetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Handset::class);
    }

    public function findByInstallationId(string $installationId): ?Handset
    {
        return $this->findOneBy(['installationId' => $installationId]);
    }

    /**
     * The inventory: every handset, most recently seen first.
     *
     * @return list<Handset>
     */
    public function findInventory(): array
    {
        return $this->createQueryBuilder('h')
            ->orderBy('h.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Installations per (platform, machine) — the physical phones behind the rows.
     *
     * @return list<array{platform: string, machine: string|null, installations: int, enrollments: int}>
     */
    public function countByMachine(): array
    {
        $rows = $this->createQueryBuilder('h')
            ->select('h.platform AS platform, h.machine AS machine, COUNT(h.id) AS installations, SUM(h.enrollmentCount) AS enrollments')
            ->groupBy('h.platform, h.machine')
            ->orderBy('enrollments', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'platform' => (string) $r['platform'],
            'machine' => $r['machine'] === null ? null : (string) $r['machine'],
            'installations' => (int) $r['installations'],
            'enrollments' => (int) $r['enrollments'],
        ], $rows);
    }
}
