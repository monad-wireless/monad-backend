<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LabEvidenceManifest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LabEvidenceManifest>
 */
class LabEvidenceManifestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabEvidenceManifest::class);
    }

    public function findByIdentity(string $recordingSessionId, string $manifestSha256): ?LabEvidenceManifest
    {
        return $this->findOneBy(['recordingSessionId' => $recordingSessionId, 'manifestSha256' => $manifestSha256]);
    }

    /** The one accepted seal a recording may hold, or null. */
    public function findAccepted(string $recordingSessionId): ?LabEvidenceManifest
    {
        return $this->findOneBy(['recordingSessionId' => $recordingSessionId, 'state' => LabEvidenceManifest::STATE_ACCEPTED]);
    }

    /** @return list<LabEvidenceManifest> every attempt for a recording, newest first */
    public function findForRecording(string $recordingSessionId): array
    {
        return $this->findBy(['recordingSessionId' => $recordingSessionId], ['receivedAt' => 'DESC']);
    }

    /** @return list<LabEvidenceManifest> */
    public function findPending(int $limit = 200): array
    {
        return $this->findBy(['state' => LabEvidenceManifest::STATE_PENDING], ['receivedAt' => 'ASC'], $limit);
    }
}
