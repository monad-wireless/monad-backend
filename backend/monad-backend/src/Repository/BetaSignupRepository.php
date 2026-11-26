<?php

namespace App\Repository;

use App\Entity\BetaSignup;
use App\Enum\BetaSignupStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BetaSignup>
 */
class BetaSignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BetaSignup::class);
    }

    /**
     * Case-insensitive, matching the unique index on lower(email). The register-time link and
     * the /join duplicate check both come through here so they cannot disagree.
     */
    public function findByEmail(string $email): ?BetaSignup
    {
        return $this->createQueryBuilder('s')
            ->andWhere('LOWER(s.email) = :email')->setParameter('email', mb_strtolower(trim($email)))
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** The desk's list, one status or all, newest first. @return list<BetaSignup> */
    public function findDesk(?BetaSignupStatus $status = null, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit);
        if ($status !== null) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /** Rows per status, for the funnel strip. @return array<string, int> */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS n')
            ->groupBy('s.status')
            ->getQuery()->getArrayResult();

        $out = [];
        foreach (BetaSignupStatus::cases() as $case) {
            $out[$case->value] = 0;
        }
        foreach ($rows as $row) {
            $status = $row['status'] instanceof BetaSignupStatus ? $row['status']->value : (string) $row['status'];
            $out[$status] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * The desk's list over several statuses at once, newest first. An empty list means every
     * status.
     *
     * @param list<BetaSignupStatus> $statuses
     * @return list<BetaSignup>
     */
    public function findByStatuses(array $statuses, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit);
        if ($statuses !== []) {
            $qb->andWhere('s.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Rows past the retention window: what app:beta:purge lists and, with --apply, scrubs.
     *
     * The window is measured from the invitation when one was sent and from the signup
     * otherwise, which is the sentence the /join consent text makes (JoinConsent::TEXT). So an
     * `invited` row goes by invited_at, a `new` or `declined` row by created_at. `registered`
     * rows are never candidates, and `withdrawn` rows are already scrubbed.
     *
     * @return list<BetaSignup>
     */
    public function findPurgeCandidates(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('(s.status = :invited AND s.invitedAt < :cutoff) OR (s.status IN (:fresh) AND s.createdAt < :cutoff)')
            ->setParameter('invited', BetaSignupStatus::INVITED)
            ->setParameter('fresh', [BetaSignupStatus::NEW, BetaSignupStatus::DECLINED])
            ->setParameter('cutoff', $cutoff)
            ->orderBy('s.createdAt', 'ASC')
            ->getQuery()->getResult();
    }
}
