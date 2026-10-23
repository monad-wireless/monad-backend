<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LabReferenceReceipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LabReferenceReceipt>
 */
class LabReferenceReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabReferenceReceipt::class);
    }

    public function findForSweep(string $recordingSessionId, Uuid $sweepId): ?LabReferenceReceipt
    {
        return $this->findOneBy(['recordingSessionId' => $recordingSessionId, 'sweepId' => $sweepId]);
    }

    /** @return list<LabReferenceReceipt> */
    public function findForRecording(string $recordingSessionId): array
    {
        return $this->findBy(['recordingSessionId' => $recordingSessionId], ['receivedAt' => 'ASC']);
    }

    /** @return list<LabReferenceReceipt> */
    public function findPending(int $limit = 500): array
    {
        return $this->findBy(['status' => LabReferenceReceipt::STATUS_PENDING], ['receivedAt' => 'ASC'], $limit);
    }
}
