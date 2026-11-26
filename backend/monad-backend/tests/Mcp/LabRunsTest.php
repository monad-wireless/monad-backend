<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\LabTools;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `lab_runs`, against a real PostgreSQL: the register read that hands out recording ids.
 *
 * Before it existed a session had no way to find the Counting recordings of a day: the register was
 * readable only on the admin pages, and every reference tool takes an id it could not list. The
 * properties that matter need the database: newest first, the `since` bound in local time, the seal
 * flag from an accepted manifest only, and the receipt count per recording.
 *
 * Rows are removed by id prefix rather than by truncation: the test database is shared.
 */
final class LabRunsTest extends KernelTestCase
{
    private const PREFIX = 'labruns-test-';

    private Connection $db;
    private LabTools $tools;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        $this->tools = $container->get(LabTools::class);
        $this->scrub();
    }

    protected function tearDown(): void
    {
        $this->scrub();
        parent::tearDown();
    }

    public function testListsNewestFirstWithSinceSealAndReceipts(): void
    {
        $this->session('old', '2026-10-19 10:00:00');
        $this->session('a', '2026-10-21 10:49:00');
        $this->session('b', '2026-10-21 13:15:00');
        // 21 Oct 00:30 local is 20 Oct 22:30 UTC: inside a since of 21 Oct.
        $this->session('midnight', '2026-10-20 22:30:00');
        $this->db->executeStatement(
            "INSERT INTO lab_evidence_manifests (id, recording_session_id, manifest_sha256, state, manifest, received_at)"
            . " VALUES (gen_random_uuid(), :s, :h, 'accepted', '{}'::jsonb, now())",
            ['s' => self::PREFIX . 'b', 'h' => str_repeat('a', 64)],
        );

        $out = $this->tools->runs(since: '2026-10-21');
        $ids = array_values(array_filter(
            array_column($out['runs'], 'recording_session_id'),
            static fn (string $id): bool => str_starts_with($id, self::PREFIX),
        ));

        self::assertSame([self::PREFIX . 'b', self::PREFIX . 'a', self::PREFIX . 'midnight'], $ids);
        $byId = array_column($out['runs'], null, 'recording_session_id');
        self::assertTrue($byId[self::PREFIX . 'b']['sealed']);
        self::assertFalse($byId[self::PREFIX . 'a']['sealed']);
        self::assertSame('app:1/' . self::PREFIX . 'a', $byId[self::PREFIX . 'a']['source']);
        self::assertSame(0, $byId[self::PREFIX . 'a']['sweep_receipts']);
    }

    public function testRejectsAMalformedSince(): void
    {
        self::assertArrayHasKey('error', $this->tools->runs(since: '21.10.2026'));
    }

    private function session(string $suffix, string $firstArtefactUtc): void
    {
        $this->db->executeStatement(
            "INSERT INTO lab_sessions (id, participant_id, roles, artefacts, first_artefact_at, updated_at)"
            . " VALUES (:id, '1', '[]'::jsonb, '{}'::jsonb, :t, :t)",
            ['id' => self::PREFIX . $suffix, 't' => $firstArtefactUtc],
        );
    }

    private function scrub(): void
    {
        $this->db->executeStatement('DELETE FROM lab_evidence_manifests WHERE recording_session_id LIKE :p', ['p' => self::PREFIX . '%']);
        $this->db->executeStatement('DELETE FROM lab_sessions WHERE id LIKE :p', ['p' => self::PREFIX . '%']);
    }
}
