<?php

namespace App\Fleet;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The fleet's public vital signs, read from Mimir on behalf of the website.
 *
 * WHY THIS IS HERE AND NOT ON THE WEBSITE
 * ---------------------------------------
 * Mimir holds the fleet's entire operational history and has no authentication of
 * its own (`multitenancy_enabled: false`), so the docker bridge *is* its access
 * control: nothing publishes a host port for it and nothing should. This
 * container is already on that bridge, so it reaches `mimir:9009` by container
 * DNS at no cost.
 *
 * The public site is a host process (uvicorn under systemd). Letting it query
 * Mimir directly meant publishing a host-bound port for the metrics store, which
 * is exactly the wrong direction: the most exposed process on the box acquiring a
 * route into the observability stack. It reads this endpoint instead — one
 * backend it already depends on, over loopback, with the query vocabulary fixed
 * here.
 *
 * WHAT MAY BE READ
 * ----------------
 * A closed allow-list, below. Adding a metric is an edit here, in a reviewed
 * file, rather than a query string arriving from a caller — this is deliberately
 * NOT a PromQL proxy. A proxy with an allow-list would still let the shape of the
 * question travel over the wire; here the caller cannot ask anything at all, it
 * can only receive what this class decided to publish.
 *
 * Nothing naming a tailnet address, a path, a credential, a MAC or a participant
 * appears in any query, and only the `host` label survives into the response.
 *
 * FAILURE IS SOFT AND NAMED
 * -------------------------
 * An unreachable Mimir yields `reachable: false`, never zeros. A resting fleet
 * and an unreadable one are different facts and the website renders them as
 * different sentences; collapsing them here would destroy that distinction
 * before it ever reached a template.
 */
final class FleetMetricsReader
{
    /**
     * Per-node readings. Each query returns one series per node labelled `host`,
     * so one scrape fills the whole fleet and one node's absence cannot fail the
     * others.
     *
     * @var array<string, string>
     */
    private const NODE_QUERIES = [
        'soc_temp_c' => 'csid_node_temp_celsius{monad_node_role="csi-node"}',
        'thermal_headroom_c' => 'csid_node_thermal_headroom_celsius{monad_node_role="csi-node"}',
        'nic_temp_c' => 'monad_nic_temp_celsius{driver="iwlwifi"}',
        'cpu_busy_pct' => 'monad:host_cpu_busy:rate5m * 100',
        'memory_used_pct' => 'monad:host_memory_used_pct',
        'uptime_seconds' => 'time() - node_boot_time_seconds{monad_node_role="csi-node"}',
        'monitor_frames_per_s' => 'monad_nic:monitor_frames:rate5m',
    ];

    /**
     * Whether a capture process is up, and whether records are arriving. Two
     * separate facts and they must stay separate: `capture_active` counts csid's
     * heartbeat, `capture_rate_hz` counts records landing. On 2026-08-15 five
     * nodes reported the first and one the second, and a single field would have
     * claimed five capturing nodes.
     *
     * Deliberately unfiltered by `monad_node_role`: these are produced by the
     * Loki ruler from csid's log lines and carry only `{host, unit}`, so the
     * filter the queries above use would return an empty vector here.
     *
     * `:10m`, not `:2m` (2026-09-16). csid's heartbeat is 300 s on 0.3.0 and
     * 60 s from 0.3.1; the two-minute series vanishes three minutes in every
     * five under the former (the Loki ruler writes a staleness marker when an
     * evaluation finds no line), and this reader then returned `null` for every
     * node — which the website renders as "No capture reported" — for a fleet
     * of ten capturing nodes. The ten-minute rule holds under either cadence.
     *
     * @var array<string, string>
     */
    private const CAPTURE_QUERIES = [
        'capture_active' => 'monad_csi:capture_active:10m',
        'capture_rate_hz' => 'monad_csi:capture_rate_hz:current',
    ];

    /**
     * Fleet-wide numbers. Every one returns a *single* series carrying no `host`
     * label, which is why they are read by a different reducer: a query that
     * returns several series is rejected rather than reduced, because a tile
     * silently printing the first of several would be a per-node number wearing
     * a fleet label.
     *
     * @var array<string, string>
     */
    private const SCALAR_QUERIES = [
        'nodes_reporting' => 'monad_fleet:node_reporting',
        'nodes_expected' => 'monad_fleet:node_expected_count',
        // `or vector(0)` because `count()` over an empty selector returns no
        // series at all, and an absent tile reads as "we do not know" when the
        // truth is a confident "none".
        'capture_processes' => 'count(monad_csi:capture_active:10m > 0) or vector(0)',
        'nodes_delivering' => 'count(monad_csi:capture_rate_hz:current > 0) or vector(0)',
        'csi_rate_hz' => 'sum(monad_csi:capture_rate_hz:current)',
        'frames_per_s' => 'sum(monad_nic:monitor_frames:rate5m)',
        'csi_records_session' => 'sum(monad_csi:capture_records:current)',
        'csi_bytes_session' => 'sum(monad_csi:capture_bytes:current)',
        // A min/max pair rather than one number: the honest answer has two
        // shapes, a fleet unanimously on one channel or a fleet mid-retune, and
        // a bare `min()` would print the first of several as if it were both.
        'monitor_channel_min' => 'min(monad_nic_channel{interface=~".+mon[0-9]+"})',
        'monitor_channel_max' => 'max(monad_nic_channel{interface=~".+mon[0-9]+"})',
        'hottest_node_c' => 'max(csid_node_temp_celsius{monad_node_role="csi-node"})',
        'nodes_throttled' => 'count(csid_node_throttled > 0) or vector(0)',
    ];

    /**
     * Matches the website's own snapshot TTL. The expected traffic is a lecture
     * hall of simultaneous QR scans behind one institutional NAT address, which a
     * per-IP limit would punish and a cache absorbs completely.
     */
    private const CACHE_TTL_SECONDS = 30;

    private const CACHE_KEY = 'fleet_public_snapshot';

    /**
     * The three readings a node page draws as a shape rather than a number.
     *
     * Deliberately a *different* and much shorter list than NODE_QUERIES. An
     * instant reading costs one point; a range costs one point per step, so the
     * allow-list here is priced per series and holds only what a curve actually
     * explains: how hard the node was capturing, how busy the channel was, how
     * hot the box got. Everything else stays a number.
     *
     * `capture_rate_hz` carries the same caveat it does instantaneously — the
     * fleet only delivers during a session, so a flat zero for most of a day is
     * the truth and not a gap. Gaps are `null` (see resample()), which is a
     * third thing again: no scrape landed in that step.
     *
     * @var array<string, string>
     */
    private const HISTORY_QUERIES = [
        'capture_rate_hz' => 'monad_csi:capture_rate_hz:current',
        'monitor_frames_per_s' => 'monad_nic:monitor_frames:rate5m',
        'soc_temp_c' => 'csid_node_temp_celsius{monad_node_role="csi-node"}',
    ];

    /**
     * Six hours, three-minute steps: 121 points per series.
     *
     * Chosen against what the curve has to show rather than against what Mimir
     * can serve. Six hours spans a whole night window plus the idle hours either
     * side, which is the shape worth seeing; three minutes is coarse enough that
     * a scrape gap of one interval does not punch a hole in the line, and fine
     * enough to resolve the start and end of an arm. A 15 s step over the same
     * window would be 1 440 points nobody can see on a 200 px sparkline.
     */
    private const HISTORY_WINDOW_SECONDS = 21600;

    private const HISTORY_STEP_SECONDS = 180;

    /**
     * Four times the instant TTL. A curve of the last six hours does not change
     * meaningfully in two minutes, and this is the expensive read of the two.
     */
    private const HISTORY_CACHE_TTL_SECONDS = 120;

    private const HISTORY_CACHE_KEY = 'fleet_public_history';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        /** Empty means "no metrics store configured" — reported, never faked. */
        private readonly string $metricsUrl = '',
        private readonly float $timeoutSeconds = 2.5,
    ) {
    }

    /**
     * The whole fleet, cached, in the shape the website renders.
     *
     * @return array{reachable: bool, read_at: int, nodes: array<string, array<string, mixed>>, scalars: array<string, float>}
     */
    public function snapshot(): array
    {
        if ('' === $this->metricsUrl) {
            return $this->unreachable();
        }

        try {
            /** @var array{reachable: bool, read_at: int, nodes: array<string, array<string, mixed>>, scalars: array<string, float>} $snapshot */
            $snapshot = $this->cache->get(
                self::CACHE_KEY,
                function (ItemInterface $item): array {
                    $item->expiresAfter(self::CACHE_TTL_SECONDS);

                    return $this->fetch();
                }
            );

            return $snapshot;
        } catch (\Throwable $e) {
            $this->logger->info('[fleet] metrics read failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->unreachable();
        }
    }

    /**
     * The last six hours per node, as fixed-length series on one time grid.
     *
     * Same posture as snapshot(): a closed allow-list, only the `host` label
     * survives, and an unreadable store says so rather than returning flat
     * lines. The grid is shared by every series and every node so the caller
     * can draw them against one axis without carrying timestamps per point —
     * `from`, `step` and the array index are the whole clock.
     *
     * @return array{reachable: bool, read_at: int, from: int, to: int, step: int, points: int, series: list<string>, nodes: array<string, array<string, list<float|null>>>}
     */
    public function history(): array
    {
        if ('' === $this->metricsUrl) {
            return $this->unreachableHistory();
        }

        try {
            /** @var array{reachable: bool, read_at: int, from: int, to: int, step: int, points: int, series: list<string>, nodes: array<string, array<string, list<float|null>>>} $history */
            $history = $this->cache->get(
                self::HISTORY_CACHE_KEY,
                function (ItemInterface $item): array {
                    $item->expiresAfter(self::HISTORY_CACHE_TTL_SECONDS);

                    return $this->fetchHistory();
                }
            );

            return $history;
        } catch (\Throwable $e) {
            $this->logger->info('[fleet] history read failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->unreachableHistory();
        }
    }

    /**
     * @return array{reachable: bool, read_at: int, from: int, to: int, step: int, points: int, series: list<string>, nodes: array<string, array<string, list<float|null>>>}
     */
    private function fetchHistory(): array
    {
        $step = self::HISTORY_STEP_SECONDS;
        // Snapped to the step grid so the same six hours are requested for the
        // whole cache window. Without it every request asks for a window one
        // second later than the last and Mimir's own result cache never hits.
        $to = intdiv(time(), $step) * $step;
        $from = $to - self::HISTORY_WINDOW_SECONDS;
        $points = intdiv($to - $from, $step) + 1;

        $nodes = [];
        $ok = 0;
        foreach (self::HISTORY_QUERIES as $key => $query) {
            $series = $this->rangeSeries($query, $from, $to, $step);
            if (null === $series) {
                continue;
            }
            ++$ok;
            foreach ($series as $host => $points_by_ts) {
                $nodes[$host][$key] = $this->resample($points_by_ts, $from, $step, $points);
            }
        }

        if (0 === $ok) {
            // Not one range query came back. That is an unreadable store, and
            // publishing flat lines for it would be the curve equivalent of
            // publishing zeros for a fleet we cannot see.
            return $this->unreachableHistory();
        }

        // Every node carries every allowed series, gaps included, so a caller
        // cannot mistake "this node has no such curve" for "this key is not
        // published". An absent series is an array of nulls and draws as a gap.
        foreach ($nodes as $host => $_series) {
            foreach (array_keys(self::HISTORY_QUERIES) as $key) {
                $nodes[$host][$key] ??= array_fill(0, $points, null);
            }
            ksort($nodes[$host]);
        }
        ksort($nodes);

        return [
            'reachable' => true,
            'read_at' => time(),
            'from' => $from,
            'to' => $to,
            'step' => $step,
            'points' => $points,
            'series' => array_keys(self::HISTORY_QUERIES),
            'nodes' => $nodes,
        ];
    }

    /**
     * One range query to `{host: {timestamp: value}}`. `null` marks a failed read.
     *
     * Same publication filter as instant(): a series carrying no `host` is
     * dropped rather than merged, because two nodes' curves averaged into one
     * line is a reading nobody took.
     *
     * @return array<string, array<int, float>>|null
     */
    private function rangeSeries(string $query, int $from, int $to, int $step): ?array
    {
        try {
            $response = $this->http->request('GET', rtrim($this->metricsUrl, '/').'/api/v1/query_range', [
                'query' => [
                    'query' => $query,
                    'start' => (string) $from,
                    'end' => (string) $to,
                    'step' => (string) $step,
                ],
                'timeout' => $this->timeoutSeconds,
            ]);
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->debug('[fleet] range query failed ({q}): {msg}', ['q' => $query, 'msg' => $e->getMessage()]);

            return null;
        }

        if (($payload['status'] ?? null) !== 'success') {
            return null;
        }

        $result = $payload['data']['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        $out = [];
        foreach ($result as $series) {
            $host = $series['metric']['host'] ?? null;
            if (!is_string($host) || '' === $host) {
                continue;
            }
            $byTs = [];
            foreach (($series['values'] ?? []) as $pair) {
                if (!is_array($pair) || !isset($pair[0], $pair[1])) {
                    continue;
                }
                $byTs[(int) $pair[0]] = (float) $pair[1];
            }
            $out[$host] = $byTs;
        }

        return $out;
    }

    /**
     * Mimir's own points onto our grid. A step with no sample is `null`.
     *
     * Nulls rather than a carried-forward previous value, and rather than zero.
     * A zero capture rate means "the recorder delivered nothing", a gap means
     * "nothing was scraped" — the same distinction snapshot() keeps between
     * `false` and `null`, and the reason a node that was switched off for an
     * hour must not draw as an hour of idling.
     *
     * @param array<int, float> $byTs
     *
     * @return list<float|null>
     */
    private function resample(array $byTs, int $from, int $step, int $points): array
    {
        $out = [];
        for ($i = 0; $i < $points; ++$i) {
            $ts = $from + $i * $step;
            // Mimir aligns query_range output to the step grid, so an exact hit
            // is the normal case; the tolerance covers a store that does not.
            $out[] = $byTs[$ts] ?? $byTs[$ts + 1] ?? $byTs[$ts - 1] ?? null;
        }

        return $out;
    }

    /**
     * @return array{reachable: bool, read_at: int, from: int, to: int, step: int, points: int, series: list<string>, nodes: array<string, array<string, list<float|null>>>}
     */
    private function unreachableHistory(): array
    {
        $step = self::HISTORY_STEP_SECONDS;
        $to = intdiv(time(), $step) * $step;

        return [
            'reachable' => false,
            'read_at' => time(),
            'from' => $to - self::HISTORY_WINDOW_SECONDS,
            'to' => $to,
            'step' => $step,
            'points' => 0,
            'series' => array_keys(self::HISTORY_QUERIES),
            'nodes' => [],
        ];
    }

    /**
     * @return array{reachable: bool, read_at: int, nodes: array<string, array<string, mixed>>, scalars: array<string, float>}
     */
    private function fetch(): array
    {
        $values = [];
        $capture = [];
        $scalars = [];
        $failures = 0;

        foreach (self::NODE_QUERIES as $key => $query) {
            $series = $this->instant($query);
            if (null === $series) {
                ++$failures;
                continue;
            }
            foreach ($series as $host => $value) {
                $values[$host][$key] = $value;
            }
        }

        foreach (self::CAPTURE_QUERIES as $key => $query) {
            $series = $this->instant($query);
            if (null === $series) {
                ++$failures;
                continue;
            }
            foreach ($series as $host => $value) {
                $capture[$host][$key] = $value;
            }
        }

        foreach (self::SCALAR_QUERIES as $key => $query) {
            $value = $this->scalar($query);
            if (null === $value) {
                ++$failures;
                continue;
            }
            $scalars[$key] = $value;
        }

        if ([] === $values) {
            // Nothing came back for any node. That is not a resting fleet and
            // must not be published as one.
            $this->logger->info('[fleet] empty snapshot after {n} failed queries', ['n' => $failures]);

            return $this->unreachable();
        }

        $nodes = [];
        foreach ($values as $host => $readings) {
            $nodes[$host] = [
                'values' => $readings,
                // `null` where the node exports no such series at all, which is a
                // different fact from `false`/`0.0` and must survive the wire:
                // monad01 is the injector, reports temperature and frames, and has
                // no `monad_csi:*` series whatever. Coerced to a boolean it would
                // assert a resting capture process on a node that runs none.
                'capture_active' => isset($capture[$host]['capture_active'])
                    ? $capture[$host]['capture_active'] > 0.0
                    : null,
                'capture_rate_hz' => $capture[$host]['capture_rate_hz'] ?? null,
            ];
        }

        ksort($nodes);

        return [
            'reachable' => true,
            'read_at' => time(),
            'nodes' => $nodes,
            'scalars' => $scalars,
        ];
    }

    /**
     * One instant query to `{host: value}`. `null` marks a failed read.
     *
     * Keeping only `host` is the publication filter, not a convenience: it is
     * what stops `instance`, `job`, `cluster` and `monad_node_role` reaching a
     * caller. A result carrying no `host` is dropped outright — those belong in
     * SCALAR_QUERIES.
     *
     * @return array<string, float>|null
     */
    private function instant(string $query): ?array
    {
        $result = $this->query($query);
        if (null === $result) {
            return null;
        }

        $out = [];
        foreach ($result as $series) {
            $host = $series['metric']['host'] ?? null;
            $value = $series['value'][1] ?? null;
            if (is_string($host) && '' !== $host && null !== $value) {
                $out[$host] = (float) $value;
            }
        }

        return $out;
    }

    /**
     * One instant query to a single fleet-wide number. Anything returning more
     * than one series is rejected rather than reduced.
     */
    private function scalar(string $query): ?float
    {
        $result = $this->query($query);
        if (null === $result || 1 !== count($result)) {
            if (null !== $result) {
                $this->logger->warning('[fleet] scalar query returned {n} series: {q}', [
                    'n' => count($result),
                    'q' => $query,
                ]);
            }

            return null;
        }

        $value = reset($result)['value'][1] ?? null;

        return null === $value ? null : (float) $value;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function query(string $query): ?array
    {
        try {
            $response = $this->http->request('GET', rtrim($this->metricsUrl, '/').'/api/v1/query', [
                'query' => ['query' => $query],
                'timeout' => $this->timeoutSeconds,
            ]);

            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->debug('[fleet] query failed ({q}): {msg}', ['q' => $query, 'msg' => $e->getMessage()]);

            return null;
        }

        if (($payload['status'] ?? null) !== 'success') {
            return null;
        }

        $result = $payload['data']['result'] ?? null;

        return is_array($result) ? array_values($result) : null;
    }

    /**
     * @return array{reachable: bool, read_at: int, nodes: array<string, array<string, mixed>>, scalars: array<string, float>}
     */
    private function unreachable(): array
    {
        return ['reachable' => false, 'read_at' => time(), 'nodes' => [], 'scalars' => []];
    }
}
