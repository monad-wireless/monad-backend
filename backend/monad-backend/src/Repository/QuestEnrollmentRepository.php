<?php

namespace App\Repository;

use App\Entity\Device;
use App\Entity\Handset;
use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestEnrollment>
 */
class QuestEnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestEnrollment::class);
    }

    /**
     * Find enrollment by user and quest
     *
     * @param string $userId
     * @param string $questId
     * @return QuestEnrollment|null
     */
    public function findByUserAndQuest(string $userId, string $questId): ?QuestEnrollment
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :userId')
            ->andWhere('e.quest = :questId')
            ->setParameter('userId', $userId)
            ->setParameter('questId', $questId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all enrollments for a user
     *
     * @param string $userId
     * @return QuestEnrollment[]
     */
    public function findByUser(string $userId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all enrollments for a quest
     *
     * @param string $questId
     * @return QuestEnrollment[]
     */
    public function findByQuest(string $questId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.quest = :questId')
            ->setParameter('questId', $questId)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find enrollments by status
     *
     * @param QuestEnrollmentStatus $status
     * @return QuestEnrollment[]
     */
    public function findByStatus(QuestEnrollmentStatus $status): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.status = :status')
            ->setParameter('status', $status)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active (in-progress) enrollments for a user
     *
     * @param string $userId
     * @return QuestEnrollment[]
     */
    public function findActiveByUser(string $userId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :userId')
            ->andWhere('e.status = :status')
            ->setParameter('userId', $userId)
            ->setParameter('status', QuestEnrollmentStatus::IN_PROGRESS)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find completed enrollments for a user
     *
     * @param string $userId
     * @return QuestEnrollment[]
     */
    public function findCompletedByUser(string $userId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :userId')
            ->andWhere('e.status = :status')
            ->setParameter('userId', $userId)
            ->setParameter('status', QuestEnrollmentStatus::COMPLETED)
            ->orderBy('e.completedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The participant's open (IN_PROGRESS) run of this quest, if any (IP-128).
     *
     * `$device` null means "anywhere" — used for a per-quest recurrence scope, and
     * for the plain "are you mid-quest" check. Ordered newest-first and limited to
     * one because history may legitimately hold several: nothing has ever
     * prevented a replay in this backend.
     */
    public function findOpenFor(User $user, Quest $quest, ?Device $device = null): ?QuestEnrollment
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.user = :user')
            ->andWhere('e.quest = :quest')
            ->andWhere('e.status = :status')
            ->setParameter('user', $user)
            ->setParameter('quest', $quest)
            ->setParameter('status', QuestEnrollmentStatus::IN_PROGRESS)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(1);

        if (null !== $device) {
            $qb->andWhere('e.device = :device')->setParameter('device', $device);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * The participant's most recent COMPLETED run, for the cooldown clock (IP-128).
     *
     * Ordered by `completionReceivedAt` — the server-stamped column — because
     * `completedAt` arrives in the request body and a backdated value would both
     * clear the gate and reorder this query.
     */
    public function findLastCompletedFor(User $user, Quest $quest, ?Device $device = null): ?QuestEnrollment
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.user = :user')
            ->andWhere('e.quest = :quest')
            ->andWhere('e.status = :status')
            ->setParameter('user', $user)
            ->setParameter('quest', $quest)
            ->setParameter('status', QuestEnrollmentStatus::COMPLETED)
            ->orderBy('e.completionReceivedAt', 'DESC')
            ->setMaxResults(1);

        if (null !== $device) {
            $qb->andWhere('e.device = :device')->setParameter('device', $device);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Completed runs per device slug for one user — the passport (IP-128).
     *
     * A stamp is a completed enrollment at a device, derived rather than stored:
     * a mutable counter on `User` could disagree with this history, which is the
     * failure the ground-truth design already refuses.
     *
     * @return array<string, int> slug => completed run count
     */
    public function countCompletedByDevice(User $user): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('d.slug AS slug, COUNT(e.id) AS runs')
            ->join('e.device', 'd')
            ->andWhere('e.user = :user')
            ->andWhere('e.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', QuestEnrollmentStatus::COMPLETED)
            ->groupBy('d.slug')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['slug']] = (int) $row['runs'];
        }

        return $out;
    }

    /**
     * How many DISTINCT participants have completed a run at this device (IP-128).
     *
     * Feeds the public "N people have done this here" line, which is why it counts
     * people rather than runs: runs would let one enthusiastic visitor look like a
     * crowd. The caller applies the k-anonymity floor — this returns the raw truth
     * and the presentation layer decides what is safe to say.
     */
    public function countDistinctParticipantsAtDevice(Device $device): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.user)')
            ->andWhere('e.device = :device')
            ->andWhere('e.status = :status')
            ->setParameter('device', $device)
            ->setParameter('status', QuestEnrollmentStatus::COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Everything a profile screen shows, for one user (IP-145).
     *
     * @return array{
     *   points_total: float,
     *   quests_completed: int,
     *   contribution: array{dwells: int, dwell_seconds: int, distinct_points: int, points_visited: list<string>},
     *   history: list<array{quest: string, completed_at: string|null, points: float|null}>,
     *   activity: list<array{date: string, dwells: int}>
     * }
     */
    public function statsForUser(User $user): array
    {
        // The total sums the FROZEN award, never `quests.points`. Re-valuing a quest must not
        // change what a finished walk was worth, which is the entire reason the value lives on
        // the enrollment.
        $total = (float) ($this->createQueryBuilder('e')
            ->select('COALESCE(SUM(e.pointsAwarded), 0)')
            ->andWhere('e.user = :user')
            ->andWhere('e.status = :completed')
            ->setParameter('user', $user)
            ->setParameter('completed', QuestEnrollmentStatus::COMPLETED)
            ->getQuery()
            ->getSingleScalarResult());

        /** @var list<QuestEnrollment> $completed */
        $completed = $this->createQueryBuilder('e')
            ->andWhere('e.user = :user')
            ->andWhere('e.status = :completed')
            ->setParameter('user', $user)
            ->setParameter('completed', QuestEnrollmentStatus::COMPLETED)
            ->orderBy('e.completedAt', 'DESC')
            ->getQuery()
            ->getResult();

        // The contribution block, and it is the one worth building: it answers "what did my
        // walking produce" rather than "what is my score". Counted over COMPLETED steps only,
        // because an abandoned dwell produced no usable window.
        $dwells = 0;
        $dwellSeconds = 0;
        $points = [];  // key => true, sorted at the end
        $history = [];
        // Dwells per calendar day, for the activity chart. Keyed by Y-m-d and filled in
        // below so a quiet day is a zero-height bar rather than a missing one: a chart that
        // silently drops empty days compresses a fortnight of nothing into a solid week.
        $perDay = [];

        foreach ($completed as $enrollment) {
            foreach ($enrollment->getStepCompletions() as $step) {
                $startedAt = $step->getStartedAt();
                $completedAt = $step->getCompletedAt();
                if ($startedAt === null || $completedAt === null) {
                    continue;
                }
                $elapsed = $completedAt->getTimestamp() - $startedAt->getTimestamp();
                // A negative or absurd span is a clock artefact, not a dwell. Dropped rather
                // than clamped: a summed total that quietly absorbed a bad row would read as
                // a participant having contributed time they did not.
                if ($elapsed < 0 || $elapsed > 3600) {
                    continue;
                }
                ++$dwells;
                $dwellSeconds += $elapsed;
                $day = $completedAt->format('Y-m-d');
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                foreach ((array) ($step->getStepData()['targets'] ?? []) as $target) {
                    if (!is_string($target) || $target === '') {
                        continue;
                    }
                    // The bare key, not the scanned payload. A target is recorded as the URL
                    // printed on the card (`https://…/m/MONAD-FP-01`), and the coverage plan
                    // is drawn from surveyed point KEYS. Taking the last path segment keeps
                    // the payload grammar in one place — the printed registry — instead of
                    // teaching this repository a second copy of it.
                    $key = substr(strrchr($target, '/') ?: ('/' . $target), 1);
                    if ($key !== '') {
                        $points[$key] = true;
                    }
                }
            }

            $history[] = [
                'quest' => $enrollment->getQuest()?->getName() ?? 'Unknown quest',
                'completed_at' => $enrollment->getCompletedAt()?->format(\DateTimeInterface::ATOM),
                'points' => $enrollment->getPointsAwarded(),
            ];
        }

        ksort($points);

        // Six weeks, oldest first, EVERY day present. Matches the window the sibling study
        // app charts, so a screenshot of one sits beside the other without an axis argument.
        $activity = [];
        $cursor = new \DateTimeImmutable('-41 days');
        $today = new \DateTimeImmutable('today');
        while ($cursor <= $today) {
            $day = $cursor->format('Y-m-d');
            $activity[] = ['date' => $day, 'dwells' => $perDay[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'points_total' => $total,
            'quests_completed' => count($completed),
            'contribution' => [
                'dwells' => $dwells,
                'dwell_seconds' => $dwellSeconds,
                'distinct_points' => count($points),
                // The keys themselves, so the app can draw the coverage plan. Sorted so two
                // requests that saw the same points produce the same URL, which is what makes
                // that image cacheable.
                'points_visited' => array_values(array_map('strval', array_keys($points))),
            ],
            'history' => $history,
            'activity' => $activity,
        ];
    }

    // ── The admin's read models (IP-149 Part C) ──────────────────────────────────────────────

    /**
     * Enrollments started, and how they ended, since an instant. `null` means all time.
     *
     * "Ended" is read from `status`, not from `completed_at`: an abandoned run has no
     * completion and the funnel must still count it.
     *
     * @return array{started: int, completed: int, abandoned: int, failed: int, in_progress: int}
     */
    public function activitySince(?\DateTimeImmutable $since): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e.status AS status, COUNT(e.id) AS n')
            ->groupBy('e.status');
        if ($since !== null) {
            $qb->andWhere('e.createdAt >= :since')->setParameter('since', $since);
        }
        $out = ['started' => 0, 'completed' => 0, 'abandoned' => 0, 'failed' => 0, 'in_progress' => 0];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof QuestEnrollmentStatus ? $row['status']->value : (string) $row['status'];
            $n = (int) $row['n'];
            $out['started'] += $n;
            if (array_key_exists($status, $out)) {
                $out[$status] += $n;
            }
        }

        return $out;
    }

    /**
     * Newest enrollments with their relations loaded, for the overview and the participant page.
     *
     * @return list<QuestEnrollment>
     */
    public function findRecent(int $limit = 10, ?User $user = null, ?Handset $handset = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.quest', 'q')->addSelect('q')
            ->leftJoin('e.user', 'u')->addSelect('u')
            ->leftJoin('e.device', 'd')->addSelect('d')
            ->leftJoin('e.handset', 'h')->addSelect('h')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit);
        if ($user !== null) {
            $qb->andWhere('e.user = :user')->setParameter('user', $user);
        }
        if ($handset !== null) {
            $qb->andWhere('e.handset = :handset')->setParameter('handset', $handset);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Runs per quest: the total, and the ones that started since an instant.
     *
     * One grouped query for the whole list rather than two per quest, because the Today page
     * asks about every live quest at once and a per-quest loop is where an overview page starts
     * costing more than the thing it summarises.
     *
     * @param list<Quest> $quests
     * @return array<string, array{today: int, total: int}> keyed by the quest's RFC 4122 id
     */
    public function countsForQuests(array $quests, \DateTimeImmutable $since): array
    {
        $out = [];
        foreach ($quests as $quest) {
            $out[(string) $quest->getId()] = ['today' => 0, 'total' => 0];
        }
        if ($quests === []) {
            return $out;
        }

        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.quest) AS quest_id, COUNT(e.id) AS total,
                      SUM(CASE WHEN e.createdAt >= :since THEN 1 ELSE 0 END) AS today')
            ->andWhere('e.quest IN (:quests)')->setParameter('quests', $quests)
            ->setParameter('since', $since)
            ->groupBy('e.quest')
            ->getQuery()->getArrayResult();

        foreach ($rows as $row) {
            $key = (string) $row['quest_id'];
            if (array_key_exists($key, $out)) {
                $out[$key] = ['today' => (int) $row['today'], 'total' => (int) $row['total']];
            }
        }

        return $out;
    }

    /**
     * Everything the quest-analytics page prints for one quest.
     *
     * Durations are wall-clock from the enrollment's creation to its `completed_at`, for
     * COMPLETED runs only, and only when the span is positive and under a day — the same
     * clock-artefact rule `statsForUser()` applies to dwells. Quantiles are nearest-rank on the
     * sorted list; with fewer than three samples the page prints the samples, not quantiles.
     *
     * @return array{
     *   funnel: array{started: int, completed: int, abandoned: int, failed: int, in_progress: int},
     *   durations: list<int>,
     *   quantiles: array{p10: int, p50: int, p90: int}|null,
     *   skip_reasons: list<array{error_code: string, n: int}>,
     *   step_skips: list<array{step: string, type: string, skipped: int, failed: int, completed: int}>,
     *   per_device: list<array{slug: string, completed: int, started: int}>
     * }
     */
    public function analyticsForQuest(Quest $quest): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $questId = $quest->getId()?->toRfc4122();

        $funnel = ['started' => 0, 'completed' => 0, 'abandoned' => 0, 'failed' => 0, 'in_progress' => 0];
        foreach ($conn->fetchAllAssociative(
            'SELECT status, COUNT(*) AS n FROM quest_enrollments WHERE quest_id = :q GROUP BY status',
            ['q' => $questId],
        ) as $row) {
            $funnel['started'] += (int) $row['n'];
            if (array_key_exists((string) $row['status'], $funnel)) {
                $funnel[(string) $row['status']] += (int) $row['n'];
            }
        }

        $durations = array_map('intval', $conn->fetchFirstColumn(
            <<<'SQL'
                SELECT EXTRACT(EPOCH FROM (completed_at - created_at))::int AS seconds
                  FROM quest_enrollments
                 WHERE quest_id = :q AND status = 'completed' AND completed_at IS NOT NULL
                   AND completed_at > created_at
                   AND completed_at - created_at < INTERVAL '1 day'
                 ORDER BY seconds
                SQL,
            ['q' => $questId],
        ));
        $quantiles = null;
        if (count($durations) >= 3) {
            $rank = static fn (float $p) => $durations[max(0, (int) ceil($p * count($durations)) - 1)];
            $quantiles = ['p10' => $rank(0.10), 'p50' => $rank(0.50), 'p90' => $rank(0.90)];
        }

        $skipReasons = array_map(static fn (array $r): array => [
            'error_code' => (string) ($r['error_code'] ?? '') ?: '(none)',
            'n' => (int) $r['n'],
        ], $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT COALESCE(r.error_code, '') AS error_code, COUNT(*) AS n
                  FROM quest_step_skip_records r
                  JOIN quest_step_completions c ON c.id = r.step_completion_id
                  JOIN quest_enrollments e ON e.id = c.enrollment_id
                 WHERE e.quest_id = :q
                 GROUP BY r.error_code
                 ORDER BY n DESC
                SQL,
            ['q' => $questId],
        ));

        $stepSkips = array_map(static fn (array $r): array => [
            'step' => (string) $r['step'],
            'type' => (string) $r['type'],
            'skipped' => (int) $r['skipped'],
            'failed' => (int) $r['failed'],
            'completed' => (int) $r['completed'],
        ], $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT s.name AS step, s.type AS type,
                       COUNT(*) FILTER (WHERE c.status = 'skipped')   AS skipped,
                       COUNT(*) FILTER (WHERE c.status = 'failed')    AS failed,
                       COUNT(*) FILTER (WHERE c.status = 'completed') AS completed
                  FROM quest_step_completions c
                  JOIN quest_steps s ON s.id = c.step_id
                  JOIN quest_enrollments e ON e.id = c.enrollment_id
                 WHERE e.quest_id = :q
                 GROUP BY s.id, s.name, s.type, s."order"
                 ORDER BY s."order"
                SQL,
            ['q' => $questId],
        ));

        $perDevice = array_map(static fn (array $r): array => [
            'slug' => (string) ($r['slug'] ?? '') ?: '(no node)',
            'completed' => (int) $r['completed'],
            'started' => (int) $r['started'],
        ], $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT d.slug, COUNT(*) AS started, COUNT(*) FILTER (WHERE e.status = 'completed') AS completed
                  FROM quest_enrollments e
                  LEFT JOIN devices d ON d.id = e.device_id
                 WHERE e.quest_id = :q
                 GROUP BY d.slug
                 ORDER BY started DESC
                SQL,
            ['q' => $questId],
        ));

        return [
            'funnel' => $funnel,
            'durations' => $durations,
            'quantiles' => $quantiles,
            'skip_reasons' => $skipReasons,
            'step_skips' => $stepSkips,
            'per_device' => $perDevice,
        ];
    }

}
