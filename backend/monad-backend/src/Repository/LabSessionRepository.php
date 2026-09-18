<?php

namespace App\Repository;

use App\Entity\LabSession;
use App\Entity\QuestEnrollment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The write side of the session register is native SQL, on purpose (IP-149).
 *
 * Ten handsets flush concurrently and one session's artefacts arrive in any order,
 * some within the same millisecond. Read-modify-write through the entity would
 * lose the second of two concurrent artefacts; a single `INSERT … ON CONFLICT DO
 * UPDATE SET artefacts = artefacts || excluded.artefacts` cannot. The entity is
 * mapped read-only and is what the admin reads.
 *
 * @extends ServiceEntityRepository<LabSession>
 */
class LabSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LabSession::class);
    }

    /**
     * Record one accepted artefact. Creates the row on first sight.
     *
     * @param array{bytes: int, content_type: string, transport: string, stored_at?: string} $entry
     */
    public function recordArtefact(
        string $sessionId,
        string $participantId,
        ?string $userId,
        string $filename,
        array $entry,
        ?\DateTimeImmutable $now = null,
    ): void {
        $now ??= new \DateTimeImmutable();
        $entry['stored_at'] ??= $now->format(\DateTimeInterface::ATOM);
        $artefacts = json_encode([$filename => $entry], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO lab_sessions
                    (id, participant_id, user_id, roles, artefacts, first_artefact_at, updated_at)
                VALUES
                    (:id, :participant, :user_id, '[]'::jsonb, CAST(:artefacts AS jsonb), :now, :now)
                ON CONFLICT (id) DO UPDATE SET
                    artefacts  = lab_sessions.artefacts || excluded.artefacts,
                    user_id    = COALESCE(lab_sessions.user_id, excluded.user_id),
                    updated_at = excluded.updated_at
                SQL,
            [
                'id' => $sessionId,
                'participant' => $participantId,
                'user_id' => $userId,
                'artefacts' => $artefacts,
                'now' => $now->format('Y-m-d H:i:s'),
            ],
            ['user_id' => $userId === null ? ParameterType::NULL : ParameterType::STRING],
        );
    }

    /**
     * Complete the row with its sidecar and the projections read out of it.
     *
     * `completed_at` is set once: a replayed `metadata.json` (the client re-uploads
     * its whole set on every flush) must not move the completion instant. The
     * sidecar and the projections ARE overwritten, because the bytes are the same
     * and a backfill may be supplying a sidecar the live path never saw.
     *
     * @param array<string, mixed> $sidecar decoded `metadata.json`
     */
    public function complete(
        string $sessionId,
        string $participantId,
        ?string $userId,
        array $sidecar,
        ?string $enrollmentId,
        ?string $questId,
        ?string $handsetId,
        ?\DateTimeImmutable $now = null,
    ): void {
        $now ??= new \DateTimeImmutable();
        $identity = is_array($sidecar['identity'] ?? null) ? $sidecar['identity'] : [];
        $environment = is_array($sidecar['environment'] ?? null) ? $sidecar['environment'] : [];
        $lifecycle = is_array($sidecar['lifecycle'] ?? null) ? $sidecar['lifecycle'] : [];
        $handset = is_array($environment['handset'] ?? null) ? $environment['handset'] : [];

        $roles = array_values(array_filter((array) ($identity['roles'] ?? []), 'is_string'));
        $machine = self::str($handset['machine'] ?? null) ?? self::str($environment['device_model'] ?? null);

        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO lab_sessions
                    (id, participant_id, user_id, enrollment_id, quest_id, handset_id, site, roles,
                     platform, machine, build_id, started_wall_ms, ended_wall_ms, interrupted_reason,
                     sidecar, artefacts, first_artefact_at, completed_at, updated_at)
                VALUES
                    (:id, :participant, :user_id, :enrollment_id, :quest_id, :handset_id, :site, CAST(:roles AS jsonb),
                     :platform, :machine, :build_id, :started, :ended, :interrupted,
                     CAST(:sidecar AS jsonb), '{}'::jsonb, :now, :now, :now)
                ON CONFLICT (id) DO UPDATE SET
                    user_id            = COALESCE(lab_sessions.user_id, excluded.user_id),
                    enrollment_id      = COALESCE(excluded.enrollment_id, lab_sessions.enrollment_id),
                    quest_id           = COALESCE(excluded.quest_id, lab_sessions.quest_id),
                    handset_id         = COALESCE(excluded.handset_id, lab_sessions.handset_id),
                    site               = excluded.site,
                    roles              = excluded.roles,
                    platform           = excluded.platform,
                    machine            = excluded.machine,
                    build_id           = excluded.build_id,
                    started_wall_ms    = excluded.started_wall_ms,
                    ended_wall_ms      = excluded.ended_wall_ms,
                    interrupted_reason = excluded.interrupted_reason,
                    sidecar            = excluded.sidecar,
                    completed_at       = COALESCE(lab_sessions.completed_at, excluded.completed_at),
                    updated_at         = excluded.updated_at
                SQL,
            [
                'id' => $sessionId,
                'participant' => $participantId,
                'user_id' => $userId,
                'enrollment_id' => $enrollmentId,
                'quest_id' => $questId,
                'handset_id' => $handsetId,
                'site' => self::str($identity['site'] ?? null, 128),
                'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
                'platform' => self::str($environment['platform'] ?? null, 16),
                'machine' => $machine === null ? null : mb_substr($machine, 0, 64),
                'build_id' => self::str($environment['build_id'] ?? null, 128) ?? self::str($environment['app_version'] ?? null, 128),
                'started' => self::positiveInt($lifecycle['started_wall_ms'] ?? null),
                'ended' => self::positiveInt($lifecycle['ended_wall_ms'] ?? null),
                'interrupted' => self::str($lifecycle['interrupted_reason'] ?? $sidecar['interrupted_reason'] ?? null, 2000),
                'sidecar' => json_encode($sidecar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'now' => $now->format('Y-m-d H:i:s'),
            ],
            [
                'user_id' => $userId === null ? ParameterType::NULL : ParameterType::STRING,
                'enrollment_id' => $enrollmentId === null ? ParameterType::NULL : ParameterType::STRING,
                'quest_id' => $questId === null ? ParameterType::NULL : ParameterType::STRING,
                'handset_id' => $handsetId === null ? ParameterType::NULL : ParameterType::STRING,
                'started' => ParameterType::INTEGER,
                'ended' => ParameterType::INTEGER,
            ],
        );
    }

    /**
     * Newest first, with optional filters. The admin's register page.
     *
     * @param array{quest?: string|null, platform?: string|null, complete?: bool|null, participant?: string|null} $filters
     * @return list<LabSession>
     */
    public function findRegister(array $filters = [], int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.firstArtefactAt', 'DESC')
            ->setMaxResults($limit);
        if (!empty($filters['quest'])) {
            $qb->andWhere('s.quest = :quest')->setParameter('quest', $filters['quest']);
        }
        if (!empty($filters['platform'])) {
            $qb->andWhere('s.platform = :platform')->setParameter('platform', $filters['platform']);
        }
        if (!empty($filters['participant'])) {
            $qb->andWhere('s.participantId = :participant')->setParameter('participant', $filters['participant']);
        }
        if (array_key_exists('complete', $filters) && $filters['complete'] !== null) {
            $qb->andWhere($filters['complete'] ? 's.completedAt IS NOT NULL' : 's.completedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<LabSession> */
    public function findForEnrollment(QuestEnrollment $enrollment): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.enrollment = :e')->setParameter('e', $enrollment)
            ->orderBy('s.firstArtefactAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<LabSession> */
    public function findForUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :u')->setParameter('u', $user)
            ->orderBy('s.firstArtefactAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Sessions, bytes and incomplete sessions since an instant. `null` since means all time.
     *
     * @return array{sessions: int, bytes: int, incomplete: int}
     */
    public function activitySince(?\DateTimeImmutable $since): array
    {
        // Two statements rather than `:since IS NULL OR …`: PostgreSQL cannot type a parameter
        // that is only ever compared with NULL, and fails the prepare with "indeterminate datatype".
        $sql = <<<'SQL'
            SELECT COUNT(*) AS sessions,
                   COALESCE(SUM((SELECT COALESCE(SUM((a.value->>'bytes')::bigint), 0) FROM jsonb_each(s.artefacts) a)), 0) AS bytes,
                   COUNT(*) FILTER (WHERE s.completed_at IS NULL) AS incomplete
              FROM lab_sessions s
            SQL;
        $params = [];
        if ($since !== null) {
            $sql .= ' WHERE s.first_artefact_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }
        $row = $this->getEntityManager()->getConnection()->fetchAssociative($sql, $params) ?: [];

        return [
            'sessions' => (int) ($row['sessions'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
            'incomplete' => (int) ($row['incomplete'] ?? 0),
        ];
    }

    private static function str(mixed $value, int $max = 255): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_float($value) && $value > 0) {
            return (int) $value;
        }
        if (is_string($value) && ctype_digit($value) && $value !== '0') {
            return (int) $value;
        }

        return null;
    }
}
