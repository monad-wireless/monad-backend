<?php

namespace App\Tests\Mcp;

use App\Mcp\LabTools;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `lab_placements_write` and `lab_placements_read` against a real PostgreSQL (IP-157).
 *
 * The properties under test — replace-all per floor inside one transaction, the (floor, key)
 * unique constraint, a rejected payload leaving the previous mirror in place — are database
 * properties, and a double cannot fail the way the database can.
 *
 * The fixture is the contract with `monad-knowledge lab placements-export --floor fiit-ground-0`.
 * PROVENANCE: it is that command's output verbatim, captured 2026-09-17 against PostGIS — 37 rows,
 * 27 cards and 10 nodes, `lidar` provenance and 2026-08-25 source timestamps throughout, and two
 * cards (`MONAD-FP-11`, `MONAD-SILENT-D1-IN`) that PostGIS assigns to no cell, which is what
 * exercises the null-room path. Regenerate it by re-running that command; nothing below depends
 * on the row values beyond their shape and their own counts.
 */
class LabPlacementsWriteTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/placements-fiit-ground-0.json';

    private EntityManagerInterface $em;
    private LabTools $tools;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->tools = $container->get(LabTools::class);
        $this->em->getConnection()->executeStatement('TRUNCATE lab_placements');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }

    /** @return array{floor: string, layer_cards: string, layer_nodes: string, placements: list<array<string, mixed>>} */
    private static function fixture(): array
    {
        $raw = file_get_contents(self::FIXTURE);
        self::assertNotFalse($raw);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function write(array $payload): array
    {
        return $this->tools->placementsWrite(
            $payload['floor'],
            $payload['layer_cards'],
            $payload['layer_nodes'],
            $payload['placements'],
        );
    }

    /** @return list<array{key: string, kind: string, floor: string, sync_id: string}> */
    private function rows(string $floor): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT "key", kind, floor, sync_id::text AS sync_id, room FROM lab_placements WHERE floor = :floor ORDER BY kind, "key"',
            ['floor' => $floor],
        );
    }

    /** @return list<array<string, mixed>> */
    private static function small(string $prefix = 'MONAD-T'): array
    {
        return [
            ['key' => $prefix . '-01', 'kind' => 'card', 'room' => 'library-open', 'x_m' => 1.5, 'y_m' => 2.5, 'z_cm' => 120, 'provenance' => 'surveyed', 'source_updated_at' => '2026-09-15T10:30:00+00:00'],
            ['key' => 'monad03', 'kind' => 'node', 'room' => null, 'x_m' => 9.681, 'y_m' => 11.767, 'z_cm' => null, 'provenance' => null, 'source_updated_at' => null],
        ];
    }

    // ── the contract fixture ─────────────────────────────────────────────────────────────────

    public function testTheFixtureHasTheExportShape(): void
    {
        $payload = self::fixture();
        self::assertSame(['floor', 'layer_cards', 'layer_nodes', 'placements'], array_keys($payload));
        self::assertSame('fiit-ground-0', $payload['floor']);
        foreach ($payload['placements'] as $p) {
            self::assertSame(['key', 'kind', 'room', 'x_m', 'y_m', 'z_cm', 'provenance', 'source_updated_at'], array_keys($p));
            self::assertContains($p['kind'], ['card', 'node']);
        }
    }

    public function testWritingTheFixtureMirrorsEveryRowUnderOneSyncId(): void
    {
        $payload = self::fixture();
        $cards = count(array_filter($payload['placements'], static fn (array $p): bool => $p['kind'] === 'card'));
        $nodes = count(array_filter($payload['placements'], static fn (array $p): bool => $p['kind'] === 'node'));
        $withoutRoom = array_values(array_map(
            static fn (array $p): string => $p['key'],
            array_filter($payload['placements'], static fn (array $p): bool => $p['room'] === null),
        ));

        $result = $this->write($payload);

        self::assertArrayNotHasKey('error', $result, json_encode($result));
        self::assertSame('fiit-ground-0', $result['floor']);
        self::assertSame($cards, $result['cards']);
        self::assertSame($nodes, $result['nodes']);
        self::assertSame($withoutRoom, $result['without_room']);
        self::assertNotEmpty($result['synced_at']);
        self::assertSame(['summary', 'matched', 'spare', 'mismatch', 'historical', 'synced_at'], array_keys($result['drift']));
        // Every mirrored row is either matched or spare; which one depends on the quests the
        // shared test database happens to hold, and that is not this test's question.
        self::assertSame(count($payload['placements']), $result['drift']['summary']['matched'] + $result['drift']['summary']['spare']);

        $rows = $this->rows('fiit-ground-0');
        self::assertCount(count($payload['placements']), $rows);
        self::assertCount(1, array_unique(array_column($rows, 'sync_id')), 'one sync id per write');
        self::assertSame($result['sync_id'], $rows[0]['sync_id']);
    }

    // ── replace-all ──────────────────────────────────────────────────────────────────────────

    public function testASecondWriteReplacesTheFloorAndLeavesOtherFloorsAlone(): void
    {
        $first = $this->write(self::fixture());
        $other = $this->write(['floor' => 'other-floor', 'layer_cards' => 'other-markers', 'layer_nodes' => 'other-fleet', 'placements' => self::small('MONAD-O')]);
        self::assertArrayNotHasKey('error', $other);

        $second = $this->write(['floor' => 'fiit-ground-0', 'layer_cards' => 'fiit-ground-markers', 'layer_nodes' => 'fiit-ground-fleet', 'placements' => self::small()]);
        self::assertArrayNotHasKey('error', $second, json_encode($second));

        $rows = $this->rows('fiit-ground-0');
        self::assertSame(['MONAD-T-01', 'monad03'], array_column($rows, 'key'), 'the old set is gone, not merged');
        self::assertNotSame($first['sync_id'], $second['sync_id']);
        self::assertSame([$second['sync_id']], array_values(array_unique(array_column($rows, 'sync_id'))));

        self::assertCount(2, $this->rows('other-floor'), 'a write names one floor and touches only that floor');
    }

    public function testTheSameKeyMayExistOnTwoFloors(): void
    {
        // (floor, key) is the unique constraint, not key alone: a node that moves between
        // buildings is mirrored wherever it stands.
        $a = $this->write(['floor' => 'floor-a', 'layer_cards' => 'a-markers', 'layer_nodes' => 'a-fleet', 'placements' => self::small()]);
        $b = $this->write(['floor' => 'floor-b', 'layer_cards' => 'b-markers', 'layer_nodes' => 'b-fleet', 'placements' => self::small()]);

        self::assertArrayNotHasKey('error', $a);
        self::assertArrayNotHasKey('error', $b);
        self::assertCount(2, $this->rows('floor-a'));
        self::assertCount(2, $this->rows('floor-b'));
    }

    // ── validation, and that a refused payload writes nothing ────────────────────────────────

    public function testARefusedPayloadLeavesThePreviousMirrorInPlace(): void
    {
        $before = $this->write(self::fixture());
        self::assertArrayNotHasKey('error', $before);

        $bad = self::small();
        $bad[] = ['key' => 'monad-t-01', 'kind' => 'card', 'room' => null, 'x_m' => 0.0, 'y_m' => 0.0, 'z_cm' => null, 'provenance' => null, 'source_updated_at' => null];
        $result = $this->write(['floor' => 'fiit-ground-0', 'layer_cards' => 'fiit-ground-markers', 'layer_nodes' => 'fiit-ground-fleet', 'placements' => $bad]);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('unchanged', $result['error']);
        self::assertCount(1, $result['violations']);
        self::assertStringContainsString('"monad-t-01" repeats "MONAD-T-01"', $result['violations'][0]);

        $rows = $this->rows('fiit-ground-0');
        self::assertCount(count(self::fixture()['placements']), $rows);
        self::assertSame([$before['sync_id']], array_values(array_unique(array_column($rows, 'sync_id'))), 'still the previous sync');
    }

    public function testAnEmptyFloorIsRefused(): void
    {
        $result = $this->write(['floor' => '   ', 'layer_cards' => 'x', 'layer_nodes' => 'y', 'placements' => self::small()]);

        self::assertArrayHasKey('error', $result);
        self::assertContains('floor must not be empty.', $result['violations']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lab_placements'));
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $rows = self::small();
        $rows[0]['kind'] = 'anchor';
        $result = $this->write(['floor' => 'f', 'layer_cards' => 'x', 'layer_nodes' => 'y', 'placements' => $rows]);

        self::assertArrayHasKey('error', $result);
        self::assertCount(1, $result['violations']);
        self::assertStringContainsString('kind must be card or node, "anchor" given', $result['violations'][0]);
    }

    public function testNonNumericCoordinatesAreRefused(): void
    {
        $rows = self::small();
        $rows[0]['x_m'] = '1.5';
        $rows[1]['y_m'] = null;
        $result = $this->write(['floor' => 'f', 'layer_cards' => 'x', 'layer_nodes' => 'y', 'placements' => $rows]);

        self::assertArrayHasKey('error', $result);
        self::assertCount(2, $result['violations']);
        self::assertStringContainsString('placements[0] (MONAD-T-01): x_m and y_m must be numbers', $result['violations'][0]);
        self::assertStringContainsString('placements[1] (monad03): x_m and y_m must be numbers', $result['violations'][1]);
    }

    public function testEveryViolationIsReportedAtOnce(): void
    {
        $rows = self::small();
        $rows[0]['key'] = '';
        $rows[1]['z_cm'] = 'high';
        $rows[1]['source_updated_at'] = 'not a date';
        $result = $this->write(['floor' => 'f', 'layer_cards' => '', 'layer_nodes' => 'y', 'placements' => $rows]);

        self::assertArrayHasKey('error', $result);
        self::assertCount(4, $result['violations'], json_encode($result['violations']));
    }

    // ── read ─────────────────────────────────────────────────────────────────────────────────

    public function testReadingAFloorThatWasNeverMirroredSaysSo(): void
    {
        $result = $this->tools->placementsRead('nowhere');

        self::assertSame('nowhere', $result['floor']);
        self::assertSame([], $result['placements']);
        self::assertNull($result['synced_at']);
        self::assertSame(0, $result['cards']);
        self::assertSame(0, $result['nodes']);
        self::assertStringContainsString('lab placements-export --floor nowhere', $result['note']);
        self::assertStringContainsString('lab_placements_write', $result['note']);
    }

    public function testReadEchoesWhatWasWritten(): void
    {
        $written = $this->write(['floor' => 'fiit-ground-0', 'layer_cards' => 'fiit-ground-markers', 'layer_nodes' => 'fiit-ground-fleet', 'placements' => self::small()]);

        $result = $this->tools->placementsRead('fiit-ground-0');

        self::assertArrayNotHasKey('note', $result);
        self::assertSame('fiit-ground-markers', $result['layer_cards']);
        self::assertSame('fiit-ground-fleet', $result['layer_nodes']);
        self::assertSame(1, $result['cards']);
        self::assertSame(1, $result['nodes']);
        self::assertSame($written['synced_at'], $result['synced_at']);
        self::assertSame([$written['sync_id']], $result['sync_ids']);
        self::assertContains('fiit-ground-0', $result['known_floors']);

        $byKey = array_column($result['placements'], null, 'key');
        self::assertSame('library-open', $byKey['MONAD-T-01']['room']);
        self::assertSame(120.0, $byKey['MONAD-T-01']['z_cm']);
        self::assertSame('surveyed', $byKey['MONAD-T-01']['provenance']);
        self::assertSame('2026-09-15T10:30:00+00:00', $byKey['MONAD-T-01']['source_updated_at']);
        self::assertNull($byKey['monad03']['room']);
        self::assertNull($byKey['monad03']['z_cm']);
        self::assertSame(9.681, $byKey['monad03']['x_m']);
        self::assertSame(2, $result['drift']['summary']['matched'] + $result['drift']['summary']['spare']);
    }
}
