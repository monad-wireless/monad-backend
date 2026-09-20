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
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A quest can be edited after somebody has run it (2026-09-20), against a real PostgreSQL.
 *
 * `quest_step_completions.step_id` was NOT NULL, so replacing a quest's steps meant deleting
 * rows a completion pointed at and the database refused. `lab_quest_write` was therefore
 * unusable on any quest worth editing, the catalogue grew "(retired 2026-08)" generations,
 * and the refusal surfaced as a bare "Error while executing tool" — a foreign key that took
 * an evening to identify because the reason reached neither the caller nor the log.
 *
 * Three properties, and none of them can be shown with a double: the constraint under test
 * IS the database.
 *
 * Rows are removed by name/email prefix rather than by truncation: the test database is
 * shared.
 */
final class QuestWriteOverRunsTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'overrun-test-';
    private const QUEST_PREFIX = 'Overrun test ';

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
            'DELETE FROM quest_step_completions WHERE enrollment_id IN '
            . '(SELECT e.id FROM quest_enrollments e JOIN quests q ON q.id = e.quest_id WHERE q.name LIKE :n)',
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
            ->setName('overrun')
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A quest holding one finished run, with the step frozen on the completion the way
     * `QuestController` freezes it at enrollment.
     */
    private function questWithARun(string $suffix): Quest
    {
        $quest = (new Quest())
            ->setName(self::QUEST_PREFIX . $suffix)
            ->setDescription('A quest this test owns.')
            ->setAvailableFrom(new \DateTime('2026-09-01T00:00:00+00:00'))
            ->setAvailableTo(new \DateTime('2027-06-30T23:59:00+00:00'))
            ->setPoints(5.0)
            ->setCreatedBy($this->author);

        $step = (new QuestStep())
            ->setName('Go to Fingerprint point 12')
            ->setType(QuestStepType::WALK_TO)
            ->setOrder(0)
            ->setConfig(['location' => 'FP-12', 'description' => 'Look for it.']);
        $quest->addStep($step);
        $this->em->persist($step);
        $this->em->persist($quest);

        $enrollment = (new QuestEnrollment())
            ->setUser($this->author)
            ->setQuest($quest)
            ->setStatus(QuestEnrollmentStatus::IN_PROGRESS);
        $this->em->persist($enrollment);

        $completion = (new QuestStepCompletion())
            ->setEnrollment($enrollment)
            ->setStep($step)
            ->setStatus(QuestStepCompletionStatus::COMPLETED);
        $completion->snapshotStep($step, 0);
        $this->em->persist($completion);
        $this->em->flush();

        // Detach everything before the tool runs. `lab_quest_write` arrives in its own
        // request and loads a quest and its steps, never the completions of other people's
        // runs — so leaving this fixture's completion MANAGED would put a stale
        // completion->step reference in the unit of work that production never has, and
        // Doctrine would report the just-deleted step as a new entity. The database half of
        // the fix is ON DELETE SET NULL and is what the assertions read back.
        $this->em->clear();
        // `clear()` detaches the author too, and `questWrite` stamps `created_by` from the
        // token. Re-attach it, or the tool tries to insert the User a second time.
        $this->reauthenticate();

        return $quest;
    }

    private function reauthenticate(): void
    {
        $author = $this->em->find(User::class, $this->author->getId());
        self::assertInstanceOf(User::class, $author);
        $this->author = $author;
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($author, 'api', $author->getRoles()),
        );
    }

    /** @return array<string, mixed> */
    private function completionRow(Quest $quest): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT c.step_id, c.step_snapshot FROM quest_step_completions c '
            . 'JOIN quest_enrollments e ON e.id = c.enrollment_id WHERE e.quest_id = :q',
            ['q' => (string) $quest->getId()],
        );

        self::assertIsArray($row, 'the completion should survive the rewrite');

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function newSteps(): array
    {
        return [
            ['order' => 0, 'name' => 'Before you start', 'type' => 'start',
                'config' => ['features' => ['broadcast' => true], 'description' => 'Rewritten.']],
            ['order' => 1, 'name' => 'Go to Node monad07', 'type' => 'walk_to',
                'config' => ['location' => 'monad07', 'description' => 'Rewritten.']],
        ];
    }

    public function testAQuestThatHasBeenRunCanBeRewritten(): void
    {
        $quest = $this->questWithARun('rewritable');

        $result = $this->tools->questWrite(
            name: $quest->getName(),
            description: 'Rewritten while it had a finished run.',
            available_from: '2026-09-01T00:00:00+00:00',
            steps: $this->newSteps(),
        );

        self::assertSame('replaced', $result['action']);
        self::assertSame(2, $result['steps']);
    }

    public function testTheFinishedRunKeepsWhatItWasActuallyAsked(): void
    {
        // The whole point. The step row it pointed at is gone, and the run still reports the
        // stop the participant actually walked rather than whatever replaced it.
        $quest = $this->questWithARun('keeps-evidence');

        $this->tools->questWrite(
            name: $quest->getName(),
            description: 'Rewritten.',
            available_from: '2026-09-01T00:00:00+00:00',
            steps: $this->newSteps(),
        );
        $this->em->clear();

        $row = $this->completionRow($quest);
        $snapshot = json_decode((string) $row['step_snapshot'], true);

        self::assertNull($row['step_id'], 'ON DELETE SET NULL: the pointer goes, the row stays');
        self::assertSame('Go to Fingerprint point 12', $snapshot['name']);
        self::assertSame('FP-12', $snapshot['config']['location']);
        self::assertSame('walk_to', $snapshot['type']);
    }

    public function testADeleteThatTheDatabaseRefusesSaysWhy(): void
    {
        // Deleting a quest with enrollments is still refused — quest_enrollments.quest_id is
        // NOT NULL and does not cascade, so dropping the quest would take the runs with it.
        // What changed is that the caller is told, instead of getting "Error while executing
        // tool" and a contextless log line.
        $quest = $this->questWithARun('undeletable');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/Deleting quest .* failed:/');

        $this->tools->questDelete($quest->getName());
    }
}
