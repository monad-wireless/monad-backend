<?php

declare(strict_types=1);

namespace App\Tests\Quest\Live;

use App\Entity\LabPlacement;
use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use App\Entity\QuestStep;
use App\Entity\QuestStepCompletion;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use App\Enum\QuestStepCompletionStatus;
use App\Enum\QuestStepType;
use App\Quest\Live\LiveEvent;
use App\Quest\Live\LiveRunException;
use App\Quest\Live\LiveRunService;
use App\Quest\Live\LiveStore;
use App\Quest\Live\Questions\QuestionsEngine;
use App\Quest\Live\RadioHunt\RadioHuntEngine;
use App\Quest\Live\Signal\SightingStore;
use App\Quest\Live\TargetAllocator;
use App\Repository\LabPlacementRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** A whole hunt and a whole questionnaire against a real PostgreSQL, with a mock clock. */
final class LiveRunFlowTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'live-flow-';
    private const QUEST_PREFIX = 'Live flow test ';
    private const FLOOR = 'live-test-floor';
    private const POOL = 'live-test-pool';
    private const CARDS = ['LT-FP-1' => [2.0, 2.0], 'LT-FP-2' => [18.0, 2.0], 'LT-FP-3' => [18.0, 18.0], 'LT-FP-4' => [2.0, 18.0]];
    private const NODES = ['monad91' => [0.0, 0.0], 'monad92' => [20.0, 0.0], 'monad93' => [20.0, 20.0], 'monad94' => [0.0, 20.0], 'monad95' => [10.0, 10.0]];

    private EntityManagerInterface $em;
    private Connection $db;
    private MockClock $clock;
    private LiveRunService $live;
    private LiveStore $store;
    private SightingStore $sightings;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->clock = new MockClock('2026-12-11 10:00:00 UTC');
        $this->store = new LiveStore($this->db);
        $this->sightings = new SightingStore($this->db);
        $this->live = new LiveRunService($this->em, $this->db, $this->store, $this->clock, [
            new RadioHuntEngine($this->sightings, new TargetAllocator($this->db), $container->get(LabPlacementRepository::class)),
            new QuestionsEngine(),
        ]);
        $this->scrub();
        $sync = Uuid::v4();
        foreach (self::CARDS as $key => [$x, $y]) {
            $this->em->persist(new LabPlacement($key, LabPlacement::KIND_CARD, self::FLOOR, 'test', $x, $y, $sync));
        }
        foreach (self::NODES as $key => [$x, $y]) {
            $this->em->persist(new LabPlacement($key, LabPlacement::KIND_NODE, self::FLOOR, 'test', $x, $y, $sync));
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->scrub();
        parent::tearDown();
        $this->em->close();
    }

    public function testAWholeHunt(): void
    {
        [$user, $step] = $this->start($this->hunt(['rounds' => 2]));
        $subject = Uuid::v4()->toRfc4122();

        $answer = $this->sync($user, $step, [], $subject);
        self::assertSame('monad-live/v1', $answer['protocol']);
        self::assertSame('crew', $answer['phase']);

        $answer = $this->sync($user, $step, [$this->event('answer', ['widget' => 'crew', 'value' => 3])], $subject);
        self::assertSame('seek', $answer['phase']);
        self::assertSame('Listening…', $this->widget($answer, 'meter')['label']);
        self::assertNull($this->widget($answer, 'meter')['value'], 'no signal is not cold');
        $goal = $this->target($step);

        // Far away first, then walking to the goal: colder never, warmer yes, then the zone.
        [$gx, $gy] = self::CARDS[strtoupper($goal)];
        $tones = [];
        $trends = [];
        foreach ([1.0, 0.8, 0.6, 0.4, 0.2, 0.0] as $left) {
            $this->clock->sleep(6);
            $this->heard($subject, $gx + (10 - $gx) * $left * 1.6, $gy + (10 - $gy) * $left * 1.6);
            $meter = $this->widget($this->sync($user, $step, [], $subject), 'meter');
            $tones[] = $meter['tone'];
            $trends[] = $meter['trend'];
        }
        self::assertSame('hot', end($tones));
        self::assertContains('up', $trends);
        self::assertNotContains('down', $trends);

        // A wrong card is counted and is not the goal.
        $wrong = array_values(array_diff(array_map('strtolower', array_keys(self::CARDS)), [$goal]))[0];
        $answer = $this->sync($user, $step, [$this->event('scan', ['widget' => 'scan-1', 'value' => $this->code($wrong)])], $subject);
        self::assertSame('seek', $answer['phase']);
        $answer = $this->sync($user, $step, [$this->event('scan', ['widget' => 'scan-1', 'value' => 'https://example.org/x'])], $subject);
        self::assertStringContainsString('not part of the hunt', json_encode($answer['view']));

        $answer = $this->sync($user, $step, [$this->event('scan', ['widget' => 'scan-1', 'value' => $this->code($goal)])], $subject);
        self::assertSame('hold', $answer['phase']);
        self::assertSame($this->code($goal), $this->widget($answer, 'hold')['target']['value']);

        $answer = $this->sync($user, $step, [$this->event('hold_done', ['widget' => 'hold-1', 'seconds' => 30])], $subject);
        self::assertSame('ask', $answer['phase']);
        self::assertSame('headcount', $this->widget($answer, 'number')['record']);

        $answer = $this->sync($user, $step, [$this->event('answer', ['widget' => 'count-1', 'value' => 17])], $subject);
        self::assertSame('seek', $answer['phase']);
        self::assertSame('Treasure 2 of 2', $answer['view']['title']);
        $second = $this->target($step);
        self::assertNotSame($goal, $second);

        // A stale answer of the first round changes nothing.
        $answer = $this->sync($user, $step, [$this->event('answer', ['widget' => 'count-1', 'value' => 99])], $subject);
        self::assertSame('seek', $answer['phase']);

        // Skipping is offered only after a while.
        self::assertNull($this->widget($answer, 'button'));
        $this->clock->sleep(200);
        $answer = $this->sync($user, $step, [], $subject);
        self::assertSame('skip-2', $this->widget($answer, 'button')['id']);
        $answer = $this->sync($user, $step, [$this->event('tap', ['widget' => 'skip-2'])], $subject);

        self::assertTrue($answer['done']);
        $result = $answer['result'];
        self::assertSame(3, $result['crew']);
        self::assertSame(1, $result['found']);
        self::assertSame(['found', 'skipped'], array_column($result['rounds'], 'outcome'));
        self::assertSame(1, $result['rounds'][0]['wrong_cards']);
        self::assertSame(17, $result['rounds'][0]['people_seen']);
        self::assertSame(30, $result['rounds'][0]['held_seconds']);

        // The finished run still answers, and is not changed by another sync.
        self::assertSame($result, $this->sync($user, $step, [$this->event('tap', ['widget' => 'skip-2'])], $subject)['result']);

        $kinds = array_count_values(array_column($this->store->timeline($answer['run_id']), 'kind'));
        foreach (['open' => 1, 'assigned' => 2, 'scan_wrong' => 1, 'scan_unknown' => 1, 'scan_found' => 1, 'skipped' => 1, 'finished' => 1] as $kind => $n) {
            self::assertSame($n, $kinds[$kind] ?? 0, $kind);
        }
        self::assertGreaterThan(5, $kinds['hint']);
    }

    public function testARetriedEventIsHandledOnce(): void
    {
        [$user, $step] = $this->start($this->hunt(['rounds' => 1, 'crew_question' => null]));
        $this->sync($user, $step);
        $wrong = array_values(array_diff(array_map('strtolower', array_keys(self::CARDS)), [$this->target($step)]))[0];
        $event = $this->event('scan', ['widget' => 'scan-1', 'value' => $this->code($wrong)]);

        $first = $this->sync($user, $step, [$event]);
        $again = $this->sync($user, $step, [$event]);

        self::assertSame([$event->id], $again['acked']);
        self::assertSame(1, $this->state($step)['wrong']);
        self::assertSame($first['phase'], $again['phase']);
    }

    public function testPlayersOfOnePoolAreSentToDifferentCards(): void
    {
        $goals = [];
        $steps = [];
        for ($i = 0; $i < 4; ++$i) {
            [$user, $step] = $this->start($this->hunt(['rounds' => 1, 'crew_question' => null]));
            $this->sync($user, $step);
            $goals[] = $this->target($step);
            $steps[] = [$user, $step];
        }
        self::assertCount(4, array_unique($goals));

        // A fifth one shares rather than having no goal, and the log says so.
        [$user, $step] = $this->start($this->hunt(['rounds' => 1, 'crew_question' => null]));
        $answer = $this->sync($user, $step);
        self::assertSame('seek', $answer['phase']);
        $assigned = array_values(array_filter($this->store->timeline($answer['run_id']), static fn (array $e): bool => $e['kind'] === 'assigned'));
        self::assertTrue($assigned[0]['payload']['shared']);

        // A player who left stops blocking a card.
        $this->clock->sleep(TargetAllocator::LEASE_SECONDS + 1);
        [$user, $step] = $this->start($this->hunt(['rounds' => 1, 'crew_question' => null]));
        $answer = $this->sync($user, $step);
        $assigned = array_values(array_filter($this->store->timeline($answer['run_id']), static fn (array $e): bool => $e['kind'] === 'assigned'));
        self::assertFalse($assigned[0]['payload']['shared']);
    }

    public function testASilentPhoneIsToldSoAndNeverShownCold(): void
    {
        [$user, $step] = $this->start($this->hunt(['rounds' => 1, 'crew_question' => null]));
        $this->sync($user, $step);
        $answer = $this->live->sync($user, $step->getId(), [], Uuid::v4()->toRfc4122(), false);

        self::assertSame('none', $this->widget($answer, 'meter')['tone']);
        self::assertSame('Your phone is silent', $this->widget($answer, 'meter')['label']);
    }

    public function testOldReadingsAreNotASignal(): void
    {
        [$user, $step] = $this->start($this->hunt(['rounds' => 1, 'crew_question' => null]));
        $subject = Uuid::v4()->toRfc4122();
        $this->sync($user, $step, [], $subject);
        $this->heard($subject, 10.0, 10.0);
        self::assertNotNull($this->widget($this->sync($user, $step, [], $subject), 'meter')['value']);
        $this->clock->sleep(30);

        self::assertNull($this->widget($this->sync($user, $step, [], $subject), 'meter')['value']);
    }

    public function testOnlyPhonesWithAnActiveRunAreKept(): void
    {
        $now = (float) $this->clock->now()->format('U.u');

        self::assertSame(0, $this->sightings->record('monad91', [[Uuid::v4()->toRfc4122(), $now, -60]], $now));

        [$user, $step] = $this->start($this->hunt(['rounds' => 1]));
        $subject = Uuid::v4()->toRfc4122();
        $this->sync($user, $step, [], $subject);
        self::assertSame(1, $this->sightings->record('monad91', [[$subject, $now - 86_400, -60], [Uuid::v4()->toRfc4122(), $now, -60]], $now));
    }

    public function testAHuntWithoutPositionsSaysItIsBroken(): void
    {
        [$user, $step] = $this->start($this->hunt(['floor' => 'nowhere', 'rounds' => 1]));

        self::assertSame('broken', $this->sync($user, $step)['phase']);
    }

    public function testAQuestionnaire(): void
    {
        [$user, $step] = $this->start(['engine' => 'questions', 'options' => ['questions' => [
            ['id' => 'n', 'prompt' => 'How many?', 'kind' => 'number', 'min' => 0, 'max' => 9],
            ['id' => 'c', 'prompt' => 'Which?', 'kind' => 'choice', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
        ]]]);
        $this->sync($user, $step, [$this->event('answer', ['widget' => 'n', 'value' => 12])]);
        $answer = $this->sync($user, $step, [$this->event('answer', ['widget' => 'n', 'value' => 4])]);
        self::assertSame('Question 2 of 2', $answer['view']['title']);
        $answer = $this->sync($user, $step, [$this->event('answer', ['widget' => 'c', 'value' => 'b'])]);

        self::assertTrue($answer['done']);
        self::assertSame(['n' => 4, 'c' => 'b'], $answer['result']['answers']);
    }

    public function testSomebodyElsesRunReadsAsAbsentAndAClosedOneAsConflict(): void
    {
        [$user, $step, $enrollment] = $this->start($this->hunt(['rounds' => 1]));
        try {
            $this->sync($this->makeUser(), $step);
            self::fail('a stranger must not reach the run');
        } catch (LiveRunException $e) {
            self::assertSame(404, $e->status);
        }
        $enrollment->setStatus(QuestEnrollmentStatus::COMPLETED);
        $this->em->flush();
        try {
            $this->sync($user, $step);
            self::fail('a closed run must refuse');
        } catch (LiveRunException $e) {
            self::assertSame(409, $e->status);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function hunt(array $options): array
    {
        return ['engine' => 'radio_hunt', 'options' => $options + [
            'floor' => self::FLOOR, 'pool' => self::POOL,
            'targets' => array_map(fn (string $k): array => [
                'value' => $this->code(strtolower($k)), 'label' => $k, 'room' => 'test', 'kind' => 'card',
            ], array_keys(self::CARDS)),
        ]];
    }

    private function code(string $key): string
    {
        return 'https://monad.dubec.dev/m/' . strtoupper($key);
    }

    /** @param array<string, mixed> $data */
    private function event(string $kind, array $data): LiveEvent
    {
        return new LiveEvent(Uuid::v4()->toRfc4122(), $kind, $data);
    }

    /** @param list<LiveEvent> $events @return array<string, mixed> */
    private function sync(User $user, QuestStepCompletion $step, array $events = [], ?string $subject = null): array
    {
        return $this->live->sync($user, $step->getId(), $events, $subject, true);
    }

    /** @param array<string, mixed> $answer @return array<string, mixed>|null */
    private function widget(array $answer, string $type): ?array
    {
        foreach ($answer['view']['widgets'] as $widget) {
            if ($widget['type'] === $type) {
                return $widget;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function state(QuestStepCompletion $step): array
    {
        return json_decode((string) $this->db->fetchOne('SELECT state FROM quest_live_runs WHERE step_completion_id = ?', [$step->getId()->toRfc4122()]), true);
    }

    private function target(QuestStepCompletion $step): string
    {
        return (string) $this->state($step)['target'];
    }

    /** Every node hears the phone as the model says it would from here. */
    private function heard(string $subject, float $x, float $y): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        foreach (self::NODES as $node => [$nx, $ny]) {
            $rssi = (int) round(-62.11 - 12.15 * log10(max(0.5, hypot($x - $nx, $y - $ny))));
            $this->sightings->record($node, [[$subject, $now - 1.0, $rssi], [$subject, $now - 0.5, $rssi]], $now);
        }
    }

    private function makeUser(): User
    {
        $user = (new User())->setEmail(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.test')->setName('live')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: User, 1: QuestStepCompletion, 2: QuestEnrollment}
     */
    private function start(array $config): array
    {
        $user = $this->makeUser();
        $quest = (new Quest())->setName(self::QUEST_PREFIX . bin2hex(random_bytes(4)))->setDescription('test')
            ->setAvailableFrom(new \DateTime('2026-09-01'))->setPoints(1.0)->setCreatedBy($user);
        $step = (new QuestStep())->setName('Hunt')->setType(QuestStepType::LIVE)->setOrder(0)->setConfig($config);
        $quest->addStep($step);
        $enrollment = (new QuestEnrollment())->setUser($user)->setQuest($quest)->setStatus(QuestEnrollmentStatus::IN_PROGRESS);
        $completion = (new QuestStepCompletion())->setEnrollment($enrollment)->setStep($step)
            ->setStatus(QuestStepCompletionStatus::IN_PROGRESS)->snapshotStep($step, 0);
        $enrollment->addStepCompletion($completion);
        foreach ([$step, $quest, $enrollment, $completion] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [$user, $completion, $enrollment];
    }

    private function scrub(): void
    {
        $quests = 'SELECT id FROM quests WHERE name LIKE ' . $this->db->quote(self::QUEST_PREFIX . '%');
        $enrollments = "SELECT id FROM quest_enrollments WHERE quest_id IN ($quests)";
        $this->db->executeStatement("DELETE FROM live_sightings");
        $this->db->executeStatement('DELETE FROM quest_live_leases WHERE pool = ?', [self::POOL]);
        $this->db->executeStatement("DELETE FROM quest_step_completions WHERE enrollment_id IN ($enrollments)");
        $this->db->executeStatement("DELETE FROM quest_enrollments WHERE quest_id IN ($quests)");
        $this->db->executeStatement("DELETE FROM quest_steps WHERE quest_id IN ($quests)");
        $this->db->executeStatement('DELETE FROM quests WHERE name LIKE ?', [self::QUEST_PREFIX . '%']);
        $this->db->executeStatement('DELETE FROM users WHERE email LIKE ?', [self::EMAIL_PREFIX . '%']);
        $this->db->executeStatement('DELETE FROM lab_placements WHERE floor = ?', [self::FLOOR]);
    }
}
