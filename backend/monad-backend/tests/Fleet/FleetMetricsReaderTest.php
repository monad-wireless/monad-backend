<?php

namespace App\Tests\Fleet;

use App\Fleet\FleetMetricsReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * What the website is allowed to be told about the fleet.
 *
 * These are honesty rules, not plumbing. Each one is a way the endpoint could
 * report something plausible and wrong, and each has been wrong in production at
 * least once somewhere in this stack:
 *
 *  - an unreachable store reported as a resting fleet (zeros instead of a state)
 *  - a node with no capture series reported as "not capturing" rather than
 *    "does not say" — monad01 is the injector and exports none
 *  - a fleet-wide tile silently printing the first of several series
 *  - a label other than `host` reaching the caller
 */
class FleetMetricsReaderTest extends TestCase
{
    /** @param array<string, string> $responses query-substring => JSON body */
    private function reader(array $responses, string $url = 'http://mimir:9009/prometheus'): FleetMetricsReader
    {
        $client = new MockHttpClient(function (string $method, string $requestUrl) use ($responses): MockResponse {
            foreach ($responses as $needle => $body) {
                if (str_contains(urldecode($requestUrl), $needle)) {
                    return new MockResponse($body, ['response_headers' => ['content-type' => 'application/json']]);
                }
            }

            return new MockResponse(
                json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
                ['response_headers' => ['content-type' => 'application/json']]
            );
        });

        return new FleetMetricsReader($client, $this->cache(), new NullLogger(), $url);
    }

    private function cache(): CacheInterface
    {
        return new TagAwareAdapter(new ArrayAdapter());
    }

    private static function vector(array $series): string
    {
        return json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $series]]);
    }

    private static function sample(array $metric, string $value): array
    {
        return ['metric' => $metric, 'value' => [1786820000, $value]];
    }

    // ---------------------------------------------------------------- reachability

    public function testAnUnconfiguredStoreIsReportedNotFaked(): void
    {
        $reader = new FleetMetricsReader(new MockHttpClient(), $this->cache(), new NullLogger(), '');

        $snapshot = $reader->snapshot();

        self::assertFalse($snapshot['reachable']);
        self::assertSame([], $snapshot['nodes']);
        self::assertSame([], $snapshot['scalars']);
    }

    public function testAnUnreachableStoreIsNotARestingFleet(): void
    {
        // Every query answers with an empty vector: nothing is readable.
        $snapshot = $this->reader([])->snapshot();

        self::assertFalse($snapshot['reachable'], 'no readings must not be published as a quiet fleet');
        self::assertSame([], $snapshot['nodes']);
    }

    // ---------------------------------------------------------------- per-node

    public function testANodeWithNoCaptureSeriesSaysNothingRatherThanNo(): void
    {
        // monad01 is the injector: it reports temperature and frames and exports
        // no `monad_csi:*` series at all. `false` would assert a stopped
        // recorder on a node that runs none.
        $snapshot = $this->reader([
            'csid_node_temp_celsius' => self::vector([
                self::sample(['host' => 'monad01'], '68.85'),
                self::sample(['host' => 'monad02'], '77.1'),
            ]),
            'monad_csi:capture_active:10m' => self::vector([
                self::sample(['host' => 'monad02'], '12'),
            ]),
            'monad_csi:capture_rate_hz:current' => self::vector([
                self::sample(['host' => 'monad02'], '0'),
            ]),
        ])->snapshot();

        self::assertTrue($snapshot['reachable']);
        self::assertNull($snapshot['nodes']['monad01']['capture_active']);
        self::assertNull($snapshot['nodes']['monad01']['capture_rate_hz']);
        self::assertTrue($snapshot['nodes']['monad02']['capture_active']);
    }

    public function testARunningProcessDeliveringNothingKeepsBothFacts(): void
    {
        // The pair that must never collapse into one field: on 2026-08-15 five
        // nodes had a capture process up and one was delivering records.
        $snapshot = $this->reader([
            'csid_node_temp_celsius' => self::vector([self::sample(['host' => 'monad02'], '77.1')]),
            'monad_csi:capture_active:10m' => self::vector([self::sample(['host' => 'monad02'], '12')]),
            'monad_csi:capture_rate_hz:current' => self::vector([self::sample(['host' => 'monad02'], '0')]),
        ])->snapshot();

        self::assertTrue($snapshot['nodes']['monad02']['capture_active']);
        self::assertSame(0.0, $snapshot['nodes']['monad02']['capture_rate_hz']);
    }

    public function testOnlyTheHostLabelSurvives(): void
    {
        $snapshot = $this->reader([
            'csid_node_temp_celsius' => self::vector([
                self::sample([
                    'host' => 'monad02',
                    'instance' => 'monad02',
                    'job' => 'integrations/unix',
                    'cluster' => 'monad',
                    'monad_node_role' => 'csi-node',
                ], '77.1'),
            ]),
        ])->snapshot();

        $encoded = json_encode($snapshot);
        self::assertStringContainsString('monad02', $encoded);
        foreach (['integrations/unix', 'cluster', 'csi-node', 'instance'] as $leak) {
            self::assertStringNotContainsString($leak, $encoded, "label leaked: {$leak}");
        }
    }

    public function testASeriesWithNoHostIsDroppedRatherThanKeyedOnNothing(): void
    {
        $snapshot = $this->reader([
            'csid_node_temp_celsius' => self::vector([
                self::sample(['host' => 'monad02'], '77.1'),
                self::sample([], '61.0'),
            ]),
        ])->snapshot();

        self::assertSame(['monad02'], array_keys($snapshot['nodes']));
    }

    // ---------------------------------------------------------------- fleet-wide

    public function testAScalarIsPublishedWhenExactlyOneSeriesComesBack(): void
    {
        $snapshot = $this->reader([
            'csid_node_temp_celsius' => self::vector([self::sample(['host' => 'monad02'], '77.1')]),
            'monad_fleet:node_reporting' => self::vector([self::sample([], '6')]),
        ])->snapshot();

        self::assertSame(6.0, $snapshot['scalars']['nodes_reporting']);
    }

    public function testAMultiSeriesScalarIsRejectedRatherThanReduced(): void
    {
        // A tile printing the first of several would be a per-node number
        // wearing a fleet label.
        $snapshot = $this->reader([
            'csid_node_temp_celsius' => self::vector([self::sample(['host' => 'monad02'], '77.1')]),
            'monad_fleet:node_reporting' => self::vector([
                self::sample(['host' => 'monad02'], '1'),
                self::sample(['host' => 'monad03'], '1'),
            ]),
        ])->snapshot();

        self::assertArrayNotHasKey('nodes_reporting', $snapshot['scalars']);
    }

    // ---------------------------------------------------------------- caching

    public function testTheStoreIsReadOncePerWindowHoweverManyScansArrive(): void
    {
        // The expected traffic is a lecture hall of simultaneous QR scans behind
        // one NAT address. Caching is the load-shedding strategy; a per-IP limit
        // would punish exactly that case.
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(
                self::vector([self::sample(['host' => 'monad02'], '77.1')]),
                ['response_headers' => ['content-type' => 'application/json']]
            );
        });
        $reader = new FleetMetricsReader($client, $this->cache(), new NullLogger(), 'http://mimir:9009/prometheus');

        $reader->snapshot();
        $first = $calls;
        for ($i = 0; $i < 20; ++$i) {
            $reader->snapshot();
        }

        self::assertSame($first, $calls, 'a cached window must cost no upstream reads');
    }

    // ---------------------------------------------------------------- history

    /**
     * A range result on the grid the reader asks for. `$holes` are step indices
     * to omit, which is how a scrape gap is expressed upstream: the point is
     * simply absent, not zero.
     *
     * @param list<int> $holes
     */
    private static function matrix(array $metric, float $base, array $holes = []): string
    {
        $step = 180;
        $to = intdiv(time(), $step) * $step;
        $from = $to - 21600;
        $values = [];
        for ($i = 0; $i <= 21600 / $step; ++$i) {
            if (in_array($i, $holes, true)) {
                continue;
            }
            $values[] = [$from + $i * $step, (string) ($base + $i)];
        }

        return json_encode(['status' => 'success', 'data' => [
            'resultType' => 'matrix',
            'result' => [['metric' => $metric, 'values' => $values]],
        ]]);
    }

    public function testAnUnconfiguredStoreYieldsNoCurvesRatherThanFlatOnes(): void
    {
        // Flat lines at zero are the curve equivalent of publishing zeros for a
        // fleet we cannot see: plausible, drawable and false.
        $reader = new FleetMetricsReader(new MockHttpClient(), $this->cache(), new NullLogger(), '');

        $history = $reader->history();

        self::assertFalse($history['reachable']);
        self::assertSame([], $history['nodes']);
        self::assertSame(0, $history['points']);
    }

    public function testEveryCurveLandsOnOneSharedGrid(): void
    {
        $history = $this->reader([
            'monad_csi:capture_rate_hz:current' => self::matrix(['host' => 'monad02'], 100.0),
        ])->history();

        self::assertTrue($history['reachable']);
        self::assertSame(121, $history['points']);
        self::assertSame(180, $history['step']);
        self::assertSame($history['to'] - $history['from'], 21600);
        self::assertCount(121, $history['nodes']['monad02']['capture_rate_hz']);
    }

    public function testAScrapeGapIsNullAndNotZero(): void
    {
        // The distinction snapshot() keeps between `false` and `null`, drawn:
        // a node switched off for an hour must not read as an hour of idling.
        $history = $this->reader([
            'monad_csi:capture_rate_hz:current' => self::matrix(['host' => 'monad02'], 100.0, [5, 6, 7]),
        ])->history();

        $series = $history['nodes']['monad02']['capture_rate_hz'];
        self::assertNull($series[5]);
        self::assertNull($series[6]);
        self::assertSame(104.0, $series[4]);
        self::assertSame(108.0, $series[8]);
    }

    public function testEveryNodeCarriesEveryAllowedSeries(): void
    {
        // So a caller cannot read "this node has no such curve" as "this key is
        // not published". The absent one is all nulls and draws as a gap.
        $history = $this->reader([
            'monad_csi:capture_rate_hz:current' => self::matrix(['host' => 'monad02'], 100.0),
        ])->history();

        self::assertSame(
            ['capture_rate_hz', 'monitor_frames_per_s', 'soc_temp_c'],
            array_keys($history['nodes']['monad02'])
        );
        self::assertSame(
            array_fill(0, 121, null),
            $history['nodes']['monad02']['soc_temp_c']
        );
    }

    public function testASeriesWithNoHostLabelIsDroppedNotMerged(): void
    {
        // Two nodes' curves averaged into one line is a reading nobody took.
        $history = $this->reader([
            'monad_csi:capture_rate_hz:current' => self::matrix(['instance' => '10.0.0.1:9100'], 100.0),
            'csid_node_temp_celsius' => self::matrix(['host' => 'monad02'], 50.0),
        ])->history();

        self::assertSame(['monad02'], array_keys($history['nodes']));
    }

    public function testAWhollyUnreadableStoreIsNotPublishedAsAQuietFleet(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse('nope', ['http_code' => 503]));
        $reader = new FleetMetricsReader($client, $this->cache(), new NullLogger(), 'http://mimir:9009/prometheus');

        self::assertFalse($reader->history()['reachable']);
    }
}
