<?php

namespace App\Repository;

use App\Entity\Quest;
use App\Entity\QuestStepCompletion;
use App\Enum\QuestStepCompletionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestStepCompletion>
 */
class QuestStepCompletionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestStepCompletion::class);
    }

    /**
     * Find completion by enrollment and step
     *
     * @param string $enrollmentId
     * @param string $stepId
     * @return QuestStepCompletion|null
     */
    public function findByEnrollmentAndStep(string $enrollmentId, string $stepId): ?QuestStepCompletion
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.enrollment = :enrollmentId')
            ->andWhere('c.step = :stepId')
            ->setParameter('enrollmentId', $enrollmentId)
            ->setParameter('stepId', $stepId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all completions for an enrollment
     *
     * @param string $enrollmentId
     * @return QuestStepCompletion[]
     */
    public function findByEnrollment(string $enrollmentId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.enrollment = :enrollmentId')
            ->setParameter('enrollmentId', $enrollmentId)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all completions for a step
     *
     * @param string $stepId
     * @return QuestStepCompletion[]
     */
    public function findByStep(string $stepId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.step = :stepId')
            ->setParameter('stepId', $stepId)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many completions point at this quest's steps (IP-157).
     *
     * The lock rule of the quest builder: `quest_step_completions.step_id` has no ON DELETE
     * clause, so a quest with any completion cannot have its step rows replaced. The same fact
     * `lab_quest_write` learns from the database, asked before the form is rendered.
     */
    public function countForQuest(Quest $quest): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.step', 's')
            ->andWhere('s.quest = :quest')
            ->setParameter('quest', $quest)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Completions per quest, for the builder's index. Quests with none are absent.
     *
     * @return array<string, int> keyed by quest id (RFC 4122)
     */
    public function countByQuest(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(s.quest) AS quest_id, COUNT(c.id) AS n')
            ->join('c.step', 's')
            ->groupBy('s.quest')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['quest_id']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Find completions by status
     *
     * @param QuestStepCompletionStatus $status
     * @return QuestStepCompletion[]
     */
    public function findByStatus(QuestStepCompletionStatus $status): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->setParameter('status', $status)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find in-progress completions for an enrollment
     *
     * @param string $enrollmentId
     * @return QuestStepCompletion[]
     */
    public function findInProgressByEnrollment(string $enrollmentId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.enrollment = :enrollmentId')
            ->andWhere('c.status = :status')
            ->setParameter('enrollmentId', $enrollmentId)
            ->setParameter('status', QuestStepCompletionStatus::IN_PROGRESS)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find completed steps for an enrollment
     *
     * @param string $enrollmentId
     * @return QuestStepCompletion[]
     */
    public function findCompletedByEnrollment(string $enrollmentId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.enrollment = :enrollmentId')
            ->andWhere('c.status = :status')
            ->setParameter('enrollmentId', $enrollmentId)
            ->setParameter('status', QuestStepCompletionStatus::COMPLETED)
            ->orderBy('c.completedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find skipped steps for an enrollment
     *
     * @param string $enrollmentId
     * @return QuestStepCompletion[]
     */
    public function findSkippedByEnrollment(string $enrollmentId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.enrollment = :enrollmentId')
            ->andWhere('c.status = :status')
            ->setParameter('enrollmentId', $enrollmentId)
            ->setParameter('status', QuestStepCompletionStatus::SKIPPED)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find failed steps for an enrollment
     *
     * @param string $enrollmentId
     * @return QuestStepCompletion[]
     */
    public function findFailedByEnrollment(string $enrollmentId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.enrollment = :enrollmentId')
            ->andWhere('c.status = :status')
            ->setParameter('enrollmentId', $enrollmentId)
            ->setParameter('status', QuestStepCompletionStatus::FAILED)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
