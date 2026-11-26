<?php

declare(strict_types=1);

namespace App\Quest\Live;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The live tables, as SQL.
 *
 * DBAL and not entities: a sync runs every second per phone, the state is one opaque document, and
 * the row lock has to be explicit.
 */
final class LiveStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The run of one step completion, locked for this transaction. Created empty when missing.
     *
     * @return array{id: string, engine: string, state: array<string, mixed>, subject: ?string, done: bool, revision: int, created: bool}
     */
    public function lock(string $stepCompletionId, string $engine, float $now): array
    {
        $created = $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO quest_live_runs (id, step_completion_id, engine, state, created_at, updated_at)
                VALUES (:id, :completion, :engine, '{}'::jsonb, :now, :now)
                ON CONFLICT (step_completion_id) DO NOTHING
                SQL,
            ['id' => Uuid::v4()->toRfc4122(), 'completion' => $stepCompletionId, 'engine' => $engine, 'now' => self::stamp($now)],
        ) === 1;

        $row = $this->db->fetchAssociative(
            'SELECT id, engine, state, subject, done, revision FROM quest_live_runs WHERE step_completion_id = :c FOR UPDATE',
            ['c' => $stepCompletionId],
        );
        if ($row === false) {
            throw new \LogicException('The live run vanished inside its own transaction.');
        }

        return [
            'id' => (string) $row['id'],
            'engine' => (string) $row['engine'],
            'state' => json_decode((string) $row['state'], true, 64, JSON_THROW_ON_ERROR),
            'subject' => $row['subject'] === null ? null : (string) $row['subject'],
            'done' => (bool) $row['done'],
            'revision' => (int) $row['revision'],
            'created' => $created,
        ];
    }

    /** @param array<string, mixed> $state */
    public function save(string $runId, array $state, ?string $subject, bool $done, float $now): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                UPDATE quest_live_runs
                SET state = :state, subject = :subject, done = :done, revision = revision + 1, updated_at = :now
                WHERE id = :id
                SQL,
            [
                'state' => json_encode($state === [] ? new \stdClass() : $state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'subject' => $subject,
                'done' => $done ? 'true' : 'false',
                'now' => self::stamp($now),
                'id' => $runId,
            ],
        );
    }

    /** False when this phone event was recorded before: a retry, which must not be handled twice. */
    public function appendClientEvent(string $runId, LiveEvent $event, float $now): bool
    {
        return $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO quest_live_events (run_id, client_event_id, origin, kind, payload, client_wall_ms, client_mono_ns, at)
                VALUES (:run, :client, 'phone', :kind, :payload, :wall, :mono, :now)
                ON CONFLICT (run_id, client_event_id) DO NOTHING
                SQL,
            [
                'run' => $runId, 'client' => $event->id, 'kind' => $event->kind,
                'payload' => self::document($event->data), 'wall' => $event->clientWallMs,
                'mono' => $event->clientMonoNs, 'now' => self::stamp($now),
            ],
        ) === 1;
    }

    /** @param array<string, mixed> $payload */
    public function appendEngineEvent(string $runId, string $kind, array $payload, float $now): void
    {
        $this->db->executeStatement(
            "INSERT INTO quest_live_events (run_id, origin, kind, payload, at) VALUES (:run, 'engine', :kind, :payload, :now)",
            ['run' => $runId, 'kind' => $kind, 'payload' => self::document($payload), 'now' => self::stamp($now)],
        );
    }

    /**
     * The run's timeline, oldest first.
     *
     * @return list<array{origin: string, kind: string, payload: array<string, mixed>, at: string}>
     */
    public function timeline(string $runId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT origin, kind, payload, at FROM quest_live_events WHERE run_id = :r ORDER BY id',
            ['r' => $runId],
        );

        return array_map(static fn (array $r): array => [
            'origin' => (string) $r['origin'],
            'kind' => (string) $r['kind'],
            'payload' => json_decode((string) $r['payload'], true, 64, JSON_THROW_ON_ERROR),
            'at' => (string) $r['at'],
        ], $rows);
    }

    public static function stamp(float $unixSeconds): string
    {
        $seconds = (int) floor($unixSeconds);

        return gmdate('Y-m-d H:i:s', $seconds) . sprintf('.%06d+00', (int) (($unixSeconds - $seconds) * 1_000_000));
    }

    /** @param array<string, mixed> $payload */
    private static function document(array $payload): string
    {
        return json_encode($payload === [] ? new \stdClass() : $payload, JSON_THROW_ON_ERROR);
    }
}
