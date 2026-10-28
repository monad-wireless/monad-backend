<?php

namespace App\Repository;

use App\Entity\PushToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushToken>
 */
class PushTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushToken::class);
    }

    public function findByToken(string $token): ?PushToken
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** Unrevoked tokens of one user: what a push to that user fans out to. @return list<PushToken> */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')->setParameter('user', $user)
            ->andWhere('t.revokedAt IS NULL')
            ->orderBy('t.lastSeenAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** Logout and account deletion: every token the account holds goes, revoked or not. */
    public function deleteForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.user = :user')->setParameter('user', $user)
            ->getQuery()->execute();
    }
}
