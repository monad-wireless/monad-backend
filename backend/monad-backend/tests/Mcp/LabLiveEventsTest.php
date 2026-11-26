<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use App\Entity\QuestStep;
use App\Entity\QuestStepCompletion;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use App\Enum\QuestStepCompletionStatus;
use App\Enum\QuestStepType;
use App\Mcp\LabTools;
use App\Quest\Live\LiveEvent;
use App\Quest\Live\LiveStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `lab_live_runs` and `lab_live_events`, against a real PostgreSQL.
 *
 * The hint log of a radio hunt is the only continuous position trace of a player (the quest runs with
 * tracking off and `live_sightings` is purged after 15 minutes), and before these tools no session
 * could read it. What matters needs the database: the join from a recording session to its runs, the
 * kind filters, the cap, and that count answers stay out unless asked for.
 */
final class LabLiveEventsTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'live-events-';
    private const QUEST_PREFIX = 'Live events test ';
    private const RECORDING = 'liveevents-test-rec';

    private EntityManagerInterface $em;
    private Connection $db;
    private LabTools $tools;
    private LiveStore $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->tools = $container->get(LabTools::class);
        $this->store = new LiveStore($this->db);
        $this->scrub();
    }

    protected function tearDown(): void
    {
        $this->scrub();
        parent::tearDown();
        $this->em->close();
    }

    public function testReadsTheTimelineByRecordingWithFilters(): void
    {
        [$completion, $enrollment] = $this->start();
        $now = 1795692476.0;
        $run = $this->store->lock($completion->getId()->toRfc4122(), 'radio_hunt', $now);
        $this->store->save($run['id'], ['phase' => 'seek', 'rounds' => [['count' => 17]]], null, false, $now);
        $this->store->appendClientEvent($run['id'], new LiveEvent('e1', 'scan', ['value' => 'X'], 1795692487513, 1), $now + 1);
        $this->store->appendEngineEvent($run['id'], 'hint', ['position' => [9.5, 7.0], 'nodes' => 6], $now + 2);
        $this->store->appendEngineEvent($run['id'], 'hint', ['position' => [9.0, 7.5], 'nodes' => 7], $now + 4);
        $this->store->appendClientEvent($run['id'], new LiveEvent('e2', 'answer', ['widget' => 'count-1', 'value' => 17], 1795692579984, 2), $now + 5);
        $this->db->executeStatement(
            "INSERT INTO lab_sessions (id, participant_id, roles, artefacts, first_artefact_at, updated_at, enrollment_id)"
            . " VALUES (:id, '1', '[]'::jsonb, '{}'::jsonb, now(), now(), :e)",
            ['id' => self::RECORDING, 'e' => $enrollment->getId()->toRfc4122()],
        );

        $all = $this->tools->liveEvents(recording_session_id: self::RECORDING, exclude_kinds: 'answer');
        self::assertSame(1, $all['count']);
        $timeline = $all['runs'][0];
        self::assertSame(['scan', 'hint', 'hint'], array_column($timeline['events'], 'kind'));
        self::assertSame(1795692487513, $timeline['events'][0]['client_wall_ms']);
        self::assertEquals([9.5, 7.0], $timeline['events'][1]['payload']['position']);
        self::assertEqualsWithDelta($now + 2, $timeline['events'][1]['t'], 0.01);
        self::assertArrayNotHasKey('state', $timeline);

        $hints = $this->tools->liveEvents(run_id: $run['id'], kinds: 'hint', limit: 1);
        self::assertSame(2, $hints['runs'][0]['events_matching']);
        self::assertTrue($hints['runs'][0]['truncated']);
        self::assertCount(1, $hints['runs'][0]['events']);

        $listed = $this->tools->liveRuns(engine: 'radio_hunt', quest: $enrollment->getQuest()->getName());
        self::assertSame(1, $listed['count']);
        self::assertSame([self::RECORDING], $listed['runs'][0]['recording_session_ids']);
        self::assertSame(2, $listed['runs'][0]['phone_events']);
        self::assertSame(2, $listed['runs'][0]['engine_events']);
        self::assertSame('seek', $listed['runs'][0]['phase']);
    }

    public function testRefusesAmbiguousOrMalformedNames(): void
    {
        self::assertArrayHasKey('error', $this->tools->liveEvents());
        self::assertArrayHasKey('error', $this->tools->liveEvents(run_id: 'not-a-uuid'));
        self::assertArrayHasKey('error', $this->tools->liveEvents(recording_session_id: 'liveevents-test-none'));
        self::assertArrayHasKey('error', $this->tools->liveRuns(since: '26.11.2026'));
    }

    /** @return array{0: QuestStepCompletion, 1: QuestEnrollment} */
    private function start(): array
    {
        $user = (new User())->setEmail(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.test')->setName('live')->setPassword('x');
        $quest = (new Quest())->setName(self::QUEST_PREFIX . bin2hex(random_bytes(4)))->setDescription('test')
            ->setAvailableFrom(new \DateTime('2026-09-01'))->setPoints(1.0)->setCreatedBy($user);
        $step = (new QuestStep())->setName('Hunt')->setType(QuestStepType::LIVE)->setOrder(0)
            ->setConfig(['engine' => 'radio_hunt', 'options' => []]);
        $quest->addStep($step);
        $enrollment = (new QuestEnrollment())->setUser($user)->setQuest($quest)->setStatus(QuestEnrollmentStatus::IN_PROGRESS);
        $completion = (new QuestStepCompletion())->setEnrollment($enrollment)->setStep($step)
            ->setStatus(QuestStepCompletionStatus::IN_PROGRESS)->snapshotStep($step, 0);
        $enrollment->addStepCompletion($completion);
        foreach ([$user, $step, $quest, $enrollment, $completion] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [$completion, $enrollment];
    }

    private function scrub(): void
    {
        $quests = 'SELECT id FROM quests WHERE name LIKE ' . $this->db->quote(self::QUEST_PREFIX . '%');
        $enrollments = "SELECT id FROM quest_enrollments WHERE quest_id IN ($quests)";
        $this->db->executeStatement('DELETE FROM lab_sessions WHERE id LIKE ?', ['liveevents-test-%']);
        $this->db->executeStatement("DELETE FROM quest_step_completions WHERE enrollment_id IN ($enrollments)");
        $this->db->executeStatement("DELETE FROM quest_enrollments WHERE quest_id IN ($quests)");
        $this->db->executeStatement("DELETE FROM quest_steps WHERE quest_id IN ($quests)");
        $this->db->executeStatement('DELETE FROM quests WHERE name LIKE ?', [self::QUEST_PREFIX . '%']);
        $this->db->executeStatement('DELETE FROM users WHERE email LIKE ?', [self::EMAIL_PREFIX . '%']);
    }
}
