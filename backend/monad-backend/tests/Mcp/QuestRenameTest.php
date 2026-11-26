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
use App\Quest\QuestPreflight;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * `lab_quest_update`'s `new_name`, against a real PostgreSQL.
 *
 * A live quest's name is the one string a participant reads before deciding to run it, and the
 * only way to change it used to be the admin form: `lab_quest_write` replaces the step rows a
 * completion points at, which the database refused for any quest somebody had already run —
 * which is every quest worth renaming. That refusal is gone since 2026-09-20 (completions carry
 * their own `step_snapshot` and `step_id` is ON DELETE SET NULL, see QuestWriteOverRunsTest), so
 * a rewrite is now a legal way to rename. `lab_quest_update` remains the right tool for it: it
 * touches the quest row only, so it cannot renumber a step or disturb a run in flight.
 *
 * Two properties carry the tool and both need the database. A rename must survive a quest holding
 * run records, which is what `lab_quest_delete` refuses and what a double cannot reproduce. And a
 * rename onto a name another quest already holds must be refused, because every tool in LabTools
 * finds a quest by `findOneBy(['name' => …])` — a duplicate makes the reads ambiguous rather than
 * wrong, which is the harder kind of defect to notice.
 *
 * Rows are removed by name/email prefix rather than by truncation: the test database is shared.
 */
final class QuestRenameTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'rename-test-';
    private const QUEST_PREFIX = 'Rename test ';

    private EntityManagerInterface $em;
    private LabTools $tools;
    private User $author;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->tools = $container->get(LabTools::class);
        $this->scrub();
        $this->author = $this->makeUser();
        // `questWrite` attributes the quest to the authenticated account and refuses without one.
        // The MCP surface runs behind the API firewall in production; here the token is set
        // directly, because what is under test is the capability derivation and not the gate.
        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($this->author, 'api', $this->author->getRoles()),
        );
    }

    protected function tearDown(): void
    {
        $this->scrub();
        parent::tearDown();
        $this->em->close();
    }

    private function scrub(): void
    {
        $c = $this->em->getConnection();
        $c->executeStatement(
            'DELETE FROM quest_step_completions WHERE step_id IN '
            . '(SELECT s.id FROM quest_steps s JOIN quests q ON q.id = s.quest_id WHERE q.name LIKE :n)',
            ['n' => self::QUEST_PREFIX . '%'],
        );
        $c->executeStatement(
            'DELETE FROM quest_enrollments WHERE quest_id IN (SELECT id FROM quests WHERE name LIKE :n)',
            ['n' => self::QUEST_PREFIX . '%'],
        );
        $c->executeStatement(
            'DELETE FROM quest_steps WHERE quest_id IN (SELECT id FROM quests WHERE name LIKE :n)',
            ['n' => self::QUEST_PREFIX . '%'],
        );
        $c->executeStatement('DELETE FROM quests WHERE name LIKE :n', ['n' => self::QUEST_PREFIX . '%']);
        $c->executeStatement('DELETE FROM users WHERE email LIKE :e', ['e' => self::EMAIL_PREFIX . '%']);
    }

    private function makeUser(): User
    {
        $user = (new User())
            ->setEmail(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.test')
            ->setName('rename')
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function makeQuest(string $suffix, string $availableTo = '2027-06-30T23:59:00+00:00'): Quest
    {
        $quest = (new Quest())
            ->setName(self::QUEST_PREFIX . $suffix)
            ->setDescription('A quest this test owns.')
            ->setAvailableFrom(new \DateTime('2026-09-01T00:00:00+00:00'))
            ->setAvailableTo(new \DateTime($availableTo))
            ->setPoints(5.0)
            ->setCreatedBy($this->author);

        $step = (new QuestStep())
            ->setName('Before you start')
            ->setType(QuestStepType::START)
            ->setOrder(0)
            ->setConfig(['features' => ['broadcast' => true]]);
        $quest->addStep($step);
        $this->em->persist($step);
        $this->em->persist($quest);
        $this->em->flush();

        return $quest;
    }

    /** A completed step, so the quest holds the run records `lab_quest_delete` refuses to drop. */
    private function recordARunOf(Quest $quest): void
    {
        $enrollment = (new QuestEnrollment())
            ->setUser($this->author)
            ->setQuest($quest)
            ->setStatus(QuestEnrollmentStatus::IN_PROGRESS);
        $this->em->persist($enrollment);

        $completion = (new QuestStepCompletion())
            ->setEnrollment($enrollment)
            ->setStep($quest->getSteps()->first())
            ->setStatus(QuestStepCompletionStatus::COMPLETED);
        $this->em->persist($completion);
        $this->em->flush();
    }

    private function nameOf(Quest $quest): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT name FROM quests WHERE id = :id',
            ['id' => (string) $quest->getId()],
        );
    }

    /**
     * `questWrite` derives capabilities with its OWN copy of QuestPreflight's rules, and that
     * class's docblock requires the two to agree sentence for sentence. Fixing the preflight copy
     * alone left the MCP write path still producing `[]` for a broadcast-only quest, so this pins
     * the agreement rather than the one implementation that happened to be edited.
     */
    public function testABroadcastOnlyQuestWrittenThroughMcpDeclaresTheAdvertiseCapability(): void
    {
        $result = $this->tools->questWrite(
            name: self::QUEST_PREFIX . 'broadcast',
            description: 'Counting, in miniature.',
            available_from: '2026-09-01T00:00:00+00:00',
            steps: [
                ['name' => 'Before you start', 'type' => 'start', 'order' => 0,
                    'config' => ['features' => ['broadcast' => true]]],
                ['name' => 'Count', 'type' => 'observe', 'order' => 1,
                    'config' => ['prompt' => 'How many?', 'min_readings' => 5]],
                ['name' => 'Run complete', 'type' => 'finish', 'order' => 2, 'config' => []],
            ],
        );

        self::assertArrayNotHasKey('error', $result, json_encode($result));
        $quest = $this->em->getRepository(Quest::class)->findOneBy(['name' => self::QUEST_PREFIX . 'broadcast']);
        self::assertNotNull($quest);
        self::assertSame(['ble.advertise'], $quest->getRequiredCapabilities());

        // And the other copy must say the same thing about the same steps. Compared as a set, for
        // the reason spelled out in testATrackedQuestWrittenThroughMcpNeedsThePoseCapability.
        $preflight = (new QuestPreflight())->run(
            ['steps' => [
                ['name' => 'Before you start', 'type' => 'start', 'config' => ['features' => ['broadcast' => true]]],
                ['name' => 'Count', 'type' => 'observe', 'config' => ['prompt' => 'How many?', 'min_readings' => 5]],
            ]],
            [],
        );
        $actual = $quest->getRequiredCapabilities();
        $expected = $preflight['required_capabilities'];
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
    }

    /**
     * The tracked survey route, in miniature. Both derivation copies must gate it on `pose.track`
     * and neither may reach for `lidar.mesh`: the mesh needs LiDAR and the trajectory does not, so
     * a LiDAR gate would withhold the survey from every non-Pro iPhone that tracks perfectly well.
     */
    public function testATrackedQuestWrittenThroughMcpNeedsThePoseCapability(): void
    {
        $steps = [
            ['name' => 'Before you start', 'type' => 'start', 'order' => 0,
                'config' => ['features' => ['broadcast' => true, 'track' => true]]],
            ['name' => '1. Scan, then hold still', 'type' => 'probe', 'order' => 1,
                'config' => ['dwell_seconds' => 30, 'targets' => [[
                    'value' => 'https://monad.dubec.dev/m/MONAD-FP-15',
                    'label' => 'Fingerprint point 15', 'room' => 'library-open', 'kind' => 'card',
                ]]]],
        ];

        $result = $this->tools->questWrite(
            name: self::QUEST_PREFIX . 'tracked',
            description: 'Survey route (tracked), in miniature.',
            available_from: '2026-09-01T00:00:00+00:00',
            steps: $steps,
        );

        self::assertArrayNotHasKey('error', $result, json_encode($result));
        $quest = $this->em->getRepository(Quest::class)->findOneBy(['name' => self::QUEST_PREFIX . 'tracked']);
        self::assertNotNull($quest);
        self::assertContains('pose.track', $quest->getRequiredCapabilities());
        self::assertNotContains('lidar.mesh', $quest->getRequiredCapabilities());

        // The two copies must agree about the same steps; that invariant is what broke last time.
        //
        // Compared as SETS. Order is not the contract on either side — `Quest::isRunnableBy` uses
        // `array_diff` and the handset uses `containsAll` — and the two copies legitimately build
        // the list in different orders, because questWrite appends the start step's tokens after
        // the loop while QuestPreflight appends them inside it. Pinning the sequence would fail on
        // a harmless refactor and say nothing about what a phone is offered.
        $preflight = (new QuestPreflight())->run(['steps' => $steps], []);
        $actual = array_unique($quest->getRequiredCapabilities());
        $expected = array_unique($preflight['required_capabilities']);
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
    }

    public function testAQuestThatNeverBroadcastsDeclaresNothingThroughMcpEither(): void
    {
        $result = $this->tools->questWrite(
            name: self::QUEST_PREFIX . 'quiet',
            description: 'No radio role at all.',
            available_from: '2026-09-01T00:00:00+00:00',
            steps: [
                ['name' => 'Before you start', 'type' => 'start', 'order' => 0,
                    'config' => ['features' => ['broadcast' => false]]],
                ['name' => 'Count', 'type' => 'observe', 'order' => 1,
                    'config' => ['prompt' => 'How many?', 'min_readings' => 5]],
            ],
        );

        self::assertArrayNotHasKey('error', $result, json_encode($result));
        $quest = $this->em->getRepository(Quest::class)->findOneBy(['name' => self::QUEST_PREFIX . 'quiet']);
        self::assertSame([], $quest?->getRequiredCapabilities());
    }

    public function testARenameKeepsTheStepsTheRunRecordsPointAt(): void
    {
        $quest = $this->makeQuest('old');
        $this->recordARunOf($quest);
        $stepsBefore = $quest->getSteps()->count();

        $result = $this->tools->questUpdate(self::QUEST_PREFIX . 'old', new_name: self::QUEST_PREFIX . 'new');

        self::assertArrayNotHasKey('error', $result);
        self::assertSame(self::QUEST_PREFIX . 'new', $result['updated'], 'the receipt names the quest as it stands now');
        self::assertSame(self::QUEST_PREFIX . 'new', $result['changed']['name']);
        self::assertSame(self::QUEST_PREFIX . 'new', $this->nameOf($quest));
        self::assertSame($stepsBefore, $quest->getSteps()->count(), 'a rename must not touch the steps');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM quest_step_completions c JOIN quest_steps s ON s.id = c.step_id WHERE s.quest_id = :q',
            ['q' => (string) $quest->getId()],
        ));
    }

    public function testARenameOntoATakenNameIsRefusedAndChangesNothing(): void
    {
        $live = $this->makeQuest('live');
        $retired = $this->makeQuest('retired', '2026-09-15T00:00:00+00:00');

        $result = $this->tools->questUpdate($live->getName(), new_name: $retired->getName());

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('already exists', $result['error']);
        self::assertStringContainsString('hidden', $result['error'], 'the refusal says which quest is in the way');
        self::assertSame(self::QUEST_PREFIX . 'live', $this->nameOf($live));
        self::assertSame(self::QUEST_PREFIX . 'retired', $this->nameOf($retired));
    }

    public function testARefusedRenameLeavesTheOtherFieldsAlone(): void
    {
        $live = $this->makeQuest('live');
        $this->makeQuest('taken');

        $result = $this->tools->questUpdate($live->getName(), new_name: self::QUEST_PREFIX . 'taken', points: 99.0);

        self::assertArrayHasKey('error', $result);
        self::assertSame(5.0, (float) $this->em->getConnection()->fetchOne(
            'SELECT points FROM quests WHERE id = :id',
            ['id' => (string) $live->getId()],
        ), 'the rename is applied first, so a refusal cannot leave a half-updated row');
    }

    public function testRenamingAQuestToItsOwnNameIsNotAConflict(): void
    {
        $quest = $this->makeQuest('same');

        $result = $this->tools->questUpdate($quest->getName(), new_name: $quest->getName(), points: 7.0);

        self::assertArrayNotHasKey('error', $result);
        self::assertArrayNotHasKey('name', $result['changed'], 'nothing changed, so nothing is reported');
        self::assertSame(7.0, $result['changed']['points']);
    }

    public function testABlankNewNameIsRefused(): void
    {
        $quest = $this->makeQuest('blank');

        $result = $this->tools->questUpdate($quest->getName(), new_name: '   ');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('cannot be blank', $result['error']);
        self::assertSame(self::QUEST_PREFIX . 'blank', $this->nameOf($quest));
    }

    public function testANewNameIsTrimmed(): void
    {
        $this->makeQuest('trim');

        $result = $this->tools->questUpdate(self::QUEST_PREFIX . 'trim', new_name: '  ' . self::QUEST_PREFIX . 'trimmed  ');

        self::assertArrayNotHasKey('error', $result);
        self::assertSame(self::QUEST_PREFIX . 'trimmed', $result['changed']['name']);
    }
}
