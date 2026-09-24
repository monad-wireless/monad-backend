<?php

namespace App\Repository;

use App\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Device>
 */
class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    /**
     * Look a node up by the slug printed on its sticker.
     *
     * Returns inactive devices too — a decommissioned node still has labels in
     * the world, and its page should say "retired" rather than 404, which reads
     * as "you scanned something broken".
     */
    public function findBySlug(string $slug): ?Device
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * How many nodes are in service — the passport denominator.
     *
     * Deriving it means a thirteenth node changes every passport by an INSERT
     * rather than a release.
     */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.isActive = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Device[] */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.isActive = true')
            ->orderBy('d.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
