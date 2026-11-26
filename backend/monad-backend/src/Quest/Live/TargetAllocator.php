<?php

declare(strict_types=1);

namespace App\Quest\Live;

use Doctrine\DBAL\Connection;
use Random\Randomizer;

/**
 * Hands out one resource of a pool to one run at a time.
 *
 * Among the resources nobody else holds, it picks at random from those assigned least often, so
 * concurrent players go to different places and over a day the places are balanced. When every
 * candidate is held, the least-assigned one is shared rather than leaving a player without a goal;
 * the caller learns it and logs it.
 *
 * A lease expires on its own, because a player who closes the app never says goodbye.
 */
final class TargetAllocator
{
    public const LEASE_SECONDS = 120;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Must run inside the sync's transaction.
     *
     * @param list<string> $candidates
     * @return array{resource: string, shared: bool}|null null when there are no candidates
     */
    public function assign(string $pool, array $candidates, string $runId, float $now, ?Randomizer $random = null): ?array
    {
        if ($candidates === []) {
            return null;
        }
        // Two players asking in the same instant must not read the same free list.
        $this->db->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:p))', ['p' => 'live-pool:' . $pool]);
        $this->release($pool, $runId);

        $rows = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT resource, assigned_count, (run_id IS NOT NULL AND expires_at > :now) AS held FROM quest_live_leases WHERE pool = :p',
            ['p' => $pool, 'now' => LiveStore::stamp($now)],
        ) as $row) {
            $rows[(string) $row['resource']] = ['count' => (int) $row['assigned_count'], 'held' => (bool) $row['held']];
        }

        $free = array_values(array_filter($candidates, static fn (string $c): bool => !($rows[$c]['held'] ?? false)));
        $shared = $free === [];
        $choices = $shared ? $candidates : $free;
        $least = min(array_map(static fn (string $c): int => $rows[$c]['count'] ?? 0, $choices));
        $choices = array_values(array_filter($choices, static fn (string $c): bool => ($rows[$c]['count'] ?? 0) === $least));
        $picked = $choices[($random ?? new Randomizer())->getInt(0, count($choices) - 1)];

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO quest_live_leases (pool, resource, run_id, expires_at, assigned_count)
                VALUES (:p, :r, :run, :exp, 1)
                ON CONFLICT (pool, resource) DO UPDATE
                SET run_id = EXCLUDED.run_id, expires_at = EXCLUDED.expires_at,
                    assigned_count = quest_live_leases.assigned_count + 1
                SQL,
            ['p' => $pool, 'r' => $picked, 'run' => $runId, 'exp' => LiveStore::stamp($now + self::LEASE_SECONDS)],
        );

        return ['resource' => $picked, 'shared' => $shared];
    }

    /** The player is still at it. */
    public function renew(string $pool, string $runId, float $now): void
    {
        $this->db->executeStatement(
            'UPDATE quest_live_leases SET expires_at = :exp WHERE pool = :p AND run_id = :run',
            ['exp' => LiveStore::stamp($now + self::LEASE_SECONDS), 'p' => $pool, 'run' => $runId],
        );
    }

    public function release(string $pool, string $runId): void
    {
        $this->db->executeStatement(
            'UPDATE quest_live_leases SET run_id = NULL, expires_at = NULL WHERE pool = :p AND run_id = :run',
            ['p' => $pool, 'run' => $runId],
        );
    }
}
