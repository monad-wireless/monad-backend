<?php

declare(strict_types=1);

namespace App\Quest\Live\Signal;

use App\Quest\Live\LiveStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The live buffer of lab-phone sightings, written by the nodes and read by the engines.
 *
 * Two privacy rules hold here. A node forwards only lab identity frames, so a bystander's phone is
 * never sent. And a row is kept only while its phone has an active live run, so a lab phone that is
 * doing something else leaves no trace in this buffer.
 */
#[AsAlias(SignalSource::class)]
final class SightingStore implements SignalSource
{
    public const KEEP_SECONDS = 900;

    /** A node clock further off than this is not believed; the arrival time is used instead. */
    public const MAX_CLOCK_ERROR = 30.0;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<array{0: string, 1: float, 2: int}> $rows subject UUID, unix seconds, dBm
     * @return int rows kept
     */
    public function record(string $node, array $rows, float $now): int
    {
        $active = array_flip(array_map('strval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT subject FROM quest_live_runs WHERE done = FALSE AND subject IS NOT NULL AND updated_at > :since',
            ['since' => LiveStore::stamp($now - self::KEEP_SECONDS)],
        )));
        $values = [];
        $params = [];
        foreach ($rows as [$subject, $seen, $rssi]) {
            if (!isset($active[$subject])) {
                continue;
            }
            if (abs($seen - $now) > self::MAX_CLOCK_ERROR) {
                $seen = $now;
            }
            $values[] = '(?, ?, ?, ?)';
            array_push($params, $subject, $node, LiveStore::stamp(min($seen, $now)), $rssi);
        }
        if ($values !== []) {
            $this->db->executeStatement(
                'INSERT INTO live_sightings (subject, node, seen_at, rssi) VALUES ' . implode(', ', $values),
                $params,
            );
        }
        if (random_int(1, 50) === 1) {
            $this->db->executeStatement('DELETE FROM live_sightings WHERE seen_at < :old', ['old' => LiveStore::stamp($now - self::KEEP_SECONDS)]);
        }

        return count($values);
    }

    public function nodeMedians(string $subject, float $now, float $window, float $maxAge): array
    {
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                WITH newest AS (
                  SELECT max(seen_at) AS t FROM live_sightings WHERE subject = :s AND seen_at > :oldest
                )
                SELECT node, percentile_cont(0.5) WITHIN GROUP (ORDER BY rssi) AS rssi
                FROM live_sightings, newest
                WHERE subject = :s AND seen_at > newest.t - make_interval(secs => :w)
                GROUP BY node
                ORDER BY rssi DESC, node
                SQL,
            ['s' => $subject, 'oldest' => LiveStore::stamp($now - $maxAge), 'w' => $window],
        );

        return array_map(static fn (array $r): array => ['node' => (string) $r['node'], 'rssi' => (float) $r['rssi']], $rows);
    }
}
