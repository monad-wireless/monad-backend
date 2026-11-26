<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use App\Mcp\LabTools;
use App\Repository\LabSessionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Quarantine against a real PostgreSQL (Version20261023120000): the session write is SQL on a
 * read-only entity, the pair is a CHECK constraint, and `lab_runs` decides membership from a join.
 * None of the three exists in a double.
 *
 * Rows live in March 2031 and are queried with `since: 2031-03-01`, so the counts cannot meet
 * another test's rows in the shared database. Removed by prefix, never truncated.
 */
final class RunQuarantineTest extends KernelTestCase
{
    private const PREFIX = 'quarantine-test-';
    private const QUEST = 'Quarantine test quest';
    private const SINCE = '2031-03-01';

    private Connection $db;
    private EntityManagerInterface $em;
    private LabTools $tools;
    private LabSessionRepository $sessions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->tools = $container->get(LabTools::class);
        $this->sessions = $container->get(LabSessionRepository::class);
        $this->scrub();
    }

    protected function tearDown(): void
    {
        $this->scrub();
        parent::tearDown();
        $this->em->close();
    }

    public function testQuarantinedRecordingIsHiddenCountedAndShownOnRequest(): void
    {
        $this->session('in', '2031-03-02 10:00:00');
        $this->session('out', '2031-03-02 11:00:00');
        $this->sessions->quarantine(self::PREFIX . 'out', 'App Store review account');

        $default = $this->tools->runs(since: self::SINCE);
        self::assertSame([self::PREFIX . 'in'], $this->ids($default));
        self::assertSame(1, $default['quarantined_hidden']);
        self::assertNull($default['runs'][0]['quarantine']);

        $all = $this->tools->runs(since: self::SINCE, include_quarantined: true);
        self::assertSame([self::PREFIX . 'out', self::PREFIX . 'in'], $this->ids($all));
        self::assertSame(0, $all['quarantined_hidden']);
        $out = array_column($all['runs'], null, 'recording_session_id')[self::PREFIX . 'out'];
        self::assertSame('recording', $out['quarantine']['by']);
        self::assertSame('App Store review account', $out['quarantine']['reason']);
    }

    public function testAQuarantinedEnrollmentTakesItsRecordingOut(): void
    {
        $enrollment = $this->enrollment();
        $this->session('via-enrollment', '2031-03-03 10:00:00', $enrollment->getId()->toRfc4122());
        $enrollment->quarantine('reviewer run');
        $this->em->flush();

        self::assertSame([], $this->ids($this->tools->runs(since: self::SINCE)));
        $all = $this->tools->runs(since: self::SINCE, include_quarantined: true);
        self::assertSame('enrollment', $all['runs'][0]['quarantine']['by']);
        self::assertTrue($this->tools->sessionReferences(self::PREFIX . 'via-enrollment')['quarantined']);
    }

    public function testSessionWritesAreGuarded(): void
    {
        $this->session('guarded', '2031-03-04 10:00:00');
        $this->sessions->quarantine(self::PREFIX . 'guarded', 'first');

        try {
            $this->sessions->quarantine(self::PREFIX . 'guarded', 'second');
            self::fail('A second quarantine must be refused.');
        } catch (\LogicException) {
        }
        self::assertSame('first', $this->db->fetchOne(
            'SELECT quarantine_reason FROM lab_sessions WHERE id = :id', ['id' => self::PREFIX . 'guarded'],
        ));

        $this->sessions->release(self::PREFIX . 'guarded');
        self::assertSame(1, $this->tools->runs(since: self::SINCE)['count']);

        $this->expectException(\LogicException::class);
        $this->sessions->release(self::PREFIX . 'guarded');
    }

    public function testTheDatabaseRefusesAHalfSetPair(): void
    {
        $this->session('half', '2031-03-05 10:00:00');

        $this->expectException(DriverException::class);
        $this->db->executeStatement(
            'UPDATE lab_sessions SET quarantined_at = now() WHERE id = :id', ['id' => self::PREFIX . 'half'],
        );
    }

    /** @return list<string> */
    private function ids(array $out): array
    {
        return array_values(array_filter(
            array_column($out['runs'], 'recording_session_id'),
            static fn (string $id): bool => str_starts_with($id, self::PREFIX),
        ));
    }

    private function session(string $suffix, string $firstArtefactUtc, ?string $enrollmentId = null): void
    {
        $this->db->executeStatement(
            "INSERT INTO lab_sessions (id, participant_id, roles, artefacts, first_artefact_at, updated_at, enrollment_id)"
            . " VALUES (:id, '1', '[]'::jsonb, '{}'::jsonb, :t, :t, :e)",
            ['id' => self::PREFIX . $suffix, 't' => $firstArtefactUtc, 'e' => $enrollmentId],
        );
    }

    private function enrollment(): QuestEnrollment
    {
        $user = (new User())
            ->setEmail(self::PREFIX . bin2hex(random_bytes(4)) . '@example.test')
            ->setName('quarantine')
            ->setPassword('x');
        $quest = (new Quest())
            ->setName(self::QUEST)
            ->setDescription('A quest this test owns.')
            ->setAvailableFrom(new \DateTime('2031-03-01T00:00:00+00:00'))
            ->setPoints(1.0)
            ->setCreatedBy($user);
        $enrollment = (new QuestEnrollment())
            ->setUser($user)
            ->setQuest($quest)
            ->setStatus(QuestEnrollmentStatus::IN_PROGRESS);
        $this->em->persist($user);
        $this->em->persist($quest);
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    private function scrub(): void
    {
        $this->db->executeStatement('DELETE FROM lab_sessions WHERE id LIKE :p', ['p' => self::PREFIX . '%']);
        $this->db->executeStatement(
            'DELETE FROM quest_enrollments WHERE quest_id IN (SELECT id FROM quests WHERE name = :n)', ['n' => self::QUEST],
        );
        $this->db->executeStatement('DELETE FROM quests WHERE name = :n', ['n' => self::QUEST]);
        $this->db->executeStatement('DELETE FROM users WHERE email LIKE :e', ['e' => self::PREFIX . '%']);
    }
}
