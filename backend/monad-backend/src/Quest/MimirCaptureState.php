<?php

namespace App\Quest;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads "is this node capturing right now?" from the metrics store (IP-128).
 *
 * FAILS OPEN, deliberately. If Mimir is unreachable, the VPN is down, or the
 * endpoint is simply not configured, every node is treated as capturing. The
 * alternative — refusing every measurement quest during an observability outage —
 * would take the whole participant path down for a reason participants cannot
 * see and operators would not immediately connect to the metrics stack.
 *
 * Cached for a short window because the answer changes on the order of minutes
 * (a capture session starts, runs, ends) while the questions arrive per scan.
 */
final class MimirCaptureState implements CaptureStateProvider
{
    /**
     * Matches the recording rule the fleet already exports.
     *
     * `:10m`, not `:2m` (2026-09-16): csid 0.3.0 heartbeats every 300 s, so the
     * two-minute series is absent three minutes in five and a node absent from
     * the result fails open here — the arming check was inert most of the time.
     * The ten-minute rule holds under both the 300 s and the 60 s (0.3.1) cadence.
     */
    private const QUERY = 'monad_csi:capture_active:10m';

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        /** Empty disables the check entirely — see the fail-open note above. */
        private readonly string $metricsUrl = '',
        private readonly float $timeoutSeconds = 1.5,
    ) {
    }

    public function isCapturing(string $slug): bool
    {
        if ('' === $slug || '' === $this->metricsUrl) {
            return true;
        }

        try {
            /** @var array<string, bool> $active */
            $active = $this->cache->get(
                'ip128_capture_active',
                function (ItemInterface $item): array {
                    $item->expiresAfter(self::CACHE_TTL_SECONDS);

                    return $this->fetch();
                }
            );
        } catch (\Throwable $e) {
            $this->logger->info('[ip128] capture-state read failed, failing open: {msg}', ['msg' => $e->getMessage()]);

            return true;
        }

        // A node absent from the result set has no series at all — it is powered
        // down or not scraped. Fail open rather than silently refusing quests at
        // a node nobody has noticed is missing.
        return $active[$slug] ?? true;
    }

    /**
     * One instant query for the whole fleet: a scan asks about one node, but a
     * per-node query would multiply requests by fleet size for no extra freshness.
     *
     * @return array<string, bool> slug => capturing
     */
    private function fetch(): array
    {
        $response = $this->http->request('GET', rtrim($this->metricsUrl, '/').'/api/v1/query', [
            'query' => ['query' => self::QUERY],
            'timeout' => $this->timeoutSeconds,
        ]);

        $payload = $response->toArray(false);
        if (($payload['status'] ?? null) !== 'success') {
            return [];
        }

        $out = [];
        foreach ($payload['data']['result'] ?? [] as $series) {
            $host = $series['metric']['host'] ?? null;
            $value = $series['value'][1] ?? null;
            if (is_string($host) && null !== $value) {
                $out[$host] = (float) $value > 0.0;
            }
        }

        return $out;
    }
}
