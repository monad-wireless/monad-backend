<?php

namespace App\Quest;

use App\Entity\Handset;
use App\Repository\HandsetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a descriptor into the `handsets` row it belongs to (IP-149).
 *
 * One method, because there is one moment a handset is observed: a quest start.
 * The caller owns the flush — the enrollment and the handset land in the same
 * transaction, so a start that fails after this returns leaves no orphan row.
 */
final class HandsetRegistry
{
    public function __construct(
        private readonly HandsetRepository $handsets,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Find or create the row for this installation and record the observation on it.
     *
     * Not flushed here. `Handset::observe()` increments the enrollment count, so
     * calling this without creating an enrollment overstates the count — do not.
     */
    public function observe(HandsetDescriptor $descriptor, ?\DateTimeImmutable $now = null): Handset
    {
        $now ??= new \DateTimeImmutable();
        $handset = $this->handsets->findByInstallationId($descriptor->installationId());
        if ($handset === null) {
            $handset = new Handset($descriptor->installationId(), $descriptor->platform(), $now);
            $this->entityManager->persist($handset);
        }
        $handset->observe($descriptor->toArray(), $now);

        return $handset;
    }
}
