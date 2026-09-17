<?php

declare(strict_types=1);

namespace App\Tests\Quest;

use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use App\Quest\QuestSpecMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * The quest as a `lab_quest_write` document, both ways (IP-157 Phase 2).
 *
 * The contract this file exists for is the round trip over the six quests that run today:
 * `infra/lab/quests/*-2026-09.json` in the monad-knowledge checkout goes in, comes back out of
 * `toSpec()` unchanged, and every step validates through the SAME entity constraint the builder
 * and `lab_quest_write` use. No container and no database: the mapper takes a validator and
 * nothing else.
 *
 * `presence-2026-09.json` is the one expected violation. Its step 1 is a probe with `targets: []`
 * (its README calls the empty list "the study timer"); the app's ProbeStep.kt renders that step as
 * unrunnable, so the schema keeps the rule and this file keeps exactly one violation until the
 * quest is re-authored. `tests/Quest/Schema/StepSchemaTest.php` asserts the same thing one layer
 * down; when the quest is fixed, both assertions fail on purpose.
 */
final class QuestSpecMapperTest extends TestCase
{
    private QuestSpecMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new QuestSpecMapper(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }

    /** @return list<string> the published payload paths, or [] when this backend stands alone */
    private static function payloads(): array
    {
        // dirname(__DIR__, 2) is the app root (backend/monad-backend); four levels above it is
        // the monad-knowledge checkout, the same walk StepSchemaTest makes.
        $dir = dirname(__DIR__, 2) . '/../../../../infra/lab/quests';
        $files = is_dir($dir) ? glob($dir . '/*-2026-09.json') : [];

        return $files === false ? [] : array_values($files);
    }

    /** @return array<string, array<string, mixed>> basename => payload */
    private static function loadPayloads(): array
    {
        $out = [];
        foreach (self::payloads() as $file) {
            $out[basename($file)] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    // ── the round trip ──────────────────────────────────────────────────────────────────────

    public function testTheSixPublishedPayloadsRoundTripThroughTheMapper(): void
    {
        $payloads = self::loadPayloads();
        if ($payloads === []) {
            self::markTestSkipped('infra/lab/quests/*-2026-09.json not found beside this checkout (monad-knowledge layout only).');
        }
        self::assertCount(6, $payloads, 'six live quest payloads were published on 2026-09-15');

        // presence-2026-09.json step 1: the empty probe target list, and nothing else anywhere.
        $expectedViolations = [
            'presence-2026-09.json' => [
                1 => ['Field "targets" has invalid value for step type "probe": must be a non-empty list of targets.'],
            ],
        ];

        foreach ($payloads as $name => $payload) {
            $built = $this->mapper->buildSteps($payload['steps']);
            self::assertSame($expectedViolations[$name] ?? [], $built['violations'], $name);

            $quest = new Quest();
            self::assertSame([], $this->mapper->applyHeader($quest, $payload), $name);
            foreach ($built['steps'] as $step) {
                $quest->addStep($step);
            }

            $spec = $this->mapper->toSpec($quest);

            self::assertSame($payload['name'], $spec['name'], $name);
            self::assertSame($payload['description'], $spec['description'], $name);
            self::assertSame((float) $payload['points'], $spec['points'], $name);
            self::assertSame($payload['estimated_duration'], $spec['estimated_duration'], $name);
            self::assertSame($payload['audience'], $spec['audience'], $name);
            self::assertArrayNotHasKey('route_pool', $spec, $name . ' declares no route pool');

            // The instants, not their spelling: applyHeader parses and toSpec re-formats as ATOM.
            self::assertEquals(
                new \DateTimeImmutable($payload['available_from']),
                new \DateTimeImmutable($spec['available_from']),
                $name,
            );
            self::assertEquals(
                new \DateTimeImmutable($payload['available_to']),
                new \DateTimeImmutable($spec['available_to']),
                $name,
            );

            self::assertSame(QuestSpecMapper::normaliseSteps($payload['steps']), $spec['steps'], $name . ' steps');
        }
    }

    public function testExactlyOnePublishedStepIsRefusedAndItIsTheKnownOne(): void
    {
        $payloads = self::loadPayloads();
        if ($payloads === []) {
            self::markTestSkipped('infra/lab/quests/*-2026-09.json not found beside this checkout (monad-knowledge layout only).');
        }

        $refused = [];
        foreach ($payloads as $name => $payload) {
            foreach ($this->mapper->buildSteps($payload['steps'])['violations'] as $index => $messages) {
                foreach ($messages as $message) {
                    $refused[] = sprintf('%s step %d: %s', $name, $index, $message);
                }
            }
        }

        self::assertSame([
            'presence-2026-09.json step 1: Field "targets" has invalid value for step type "probe": must be a non-empty list of targets.',
        ], $refused);
    }

    // ── buildSteps ──────────────────────────────────────────────────────────────────────────

    public function testBuildStepsReportsViolationsPerStepIndexAndBuildsTheRest(): void
    {
        $built = $this->mapper->buildSteps([
            ['name' => 'Before you start', 'type' => 'start', 'config' => []],
            ['name' => 'Count', 'type' => 'observe', 'config' => ['prompt' => 'How many?']],
            ['name' => 'Run complete', 'type' => 'finish'],
        ]);

        self::assertSame([
            1 => ['Missing required field "min_readings" for step type "observe".'],
        ], $built['violations']);
        self::assertCount(3, $built['steps']);
        self::assertSame([0, 1, 2], array_map(static fn (QuestStep $s): ?int => $s->getOrder(), $built['steps']));
        self::assertSame([], $built['steps'][2]->getConfig(), 'a missing config defaults to an empty object');
    }

    public function testAnUnknownTypeIsAViolationAndBuildsNoStep(): void
    {
        $built = $this->mapper->buildSteps([['name' => 'Fly', 'type' => 'levitate', 'config' => []]]);

        self::assertSame([0 => ['Unknown step type "levitate".']], $built['violations']);
        self::assertSame([], $built['steps']);
    }

    public function testANamelessStepIsAViolation(): void
    {
        $built = $this->mapper->buildSteps([['name' => '  ', 'type' => 'finish', 'config' => []]]);

        self::assertSame([0 => ['Step name is required.']], $built['violations']);
    }

    public function testNormaliseStepsDefaultsOrderToTheIndexAndConfigToAnObject(): void
    {
        self::assertSame([
            ['order' => 0, 'name' => 'A', 'type' => 'start', 'config' => []],
            ['order' => 7, 'name' => '', 'type' => '', 'config' => ['k' => 1]],
        ], QuestSpecMapper::normaliseSteps([
            ['name' => 'A', 'type' => 'start'],
            ['order' => 7, 'config' => ['k' => 1]],
        ]));
    }

    // ── status ──────────────────────────────────────────────────────────────────────────────

    public function testStatusIsTheWindowAgainstNow(): void
    {
        $now = new \DateTimeImmutable('2026-09-17T12:00:00+00:00');

        self::assertSame('live', QuestSpecMapper::status($this->quest('2026-09-01', null), $now));
        self::assertSame('live', QuestSpecMapper::status($this->quest('2026-09-01', '2026-10-01'), $now));
        self::assertSame('scheduled', QuestSpecMapper::status($this->quest('2026-10-01', null), $now));
        self::assertSame('hidden', QuestSpecMapper::status($this->quest('2026-09-01', '2026-09-16'), $now));
        // A window entirely in the future reads as scheduled, not hidden: the open date wins.
        self::assertSame('scheduled', QuestSpecMapper::status($this->quest('2026-10-01', '2026-10-02'), $now));
    }

    private function quest(string $from, ?string $to): Quest
    {
        return (new Quest())
            ->setAvailableFrom(new \DateTime($from))
            ->setAvailableTo($to === null ? null : new \DateTime($to));
    }

    // ── applyHeader ─────────────────────────────────────────────────────────────────────────

    public function testApplyHeaderRefusesAnIncompleteSpecAndWritesNothing(): void
    {
        $quest = (new Quest())->setName('Untouched');

        $errors = $this->mapper->applyHeader($quest, ['name' => '', 'description' => '', 'points' => -1]);

        self::assertCount(3, $errors);
        self::assertSame('Untouched', $quest->getName());
    }

    public function testApplyHeaderLeavesAudienceAndRoutePoolAloneWhenOmitted(): void
    {
        $quest = (new Quest())
            ->setAudience(Quest::AUDIENCE_OPERATOR)
            ->setRoutePolicy(['mode' => 'pool', 'routes' => [['MONAD-FP-07']]]);

        self::assertSame([], $this->mapper->applyHeader($quest, [
            'name' => 'Q', 'description' => 'D', 'available_from' => '2026-09-15T00:00:00+00:00',
        ]));

        self::assertSame(Quest::AUDIENCE_OPERATOR, $quest->getAudience());
        self::assertSame([['MONAD-FP-07']], QuestSpecMapper::routesOf($quest->getRoutePolicy()));
    }

    public function testAnEmptyRoutePoolClearsThePolicy(): void
    {
        $quest = (new Quest())->setRoutePolicy(['mode' => 'pool', 'routes' => [['MONAD-FP-07']]]);

        self::assertSame([], $this->mapper->applyHeader($quest, [
            'name' => 'Q', 'description' => 'D', 'available_from' => '2026-09-15T00:00:00+00:00', 'route_pool' => [],
        ]));

        self::assertNull($quest->getRoutePolicy());
    }

    // ── routes ──────────────────────────────────────────────────────────────────────────────

    public function testRoutesOfReadsOnlyAPoolPolicy(): void
    {
        self::assertSame([], QuestSpecMapper::routesOf(null));
        self::assertSame([], QuestSpecMapper::routesOf(['mode' => 'fixed']));
        self::assertSame([], QuestSpecMapper::routesOf(['mode' => 'pool']));
        self::assertSame(
            [['MONAD-FP-15', 'monad02']],
            QuestSpecMapper::routesOf(['mode' => 'pool', 'routes' => [['MONAD-FP-15', 'monad02'], []]]),
        );
    }

    public function testARouteMayOnlyNameAKeyAProbeStepAccepts(): void
    {
        $steps = QuestSpecMapper::normaliseSteps([
            ['name' => 'Start', 'type' => 'start', 'config' => []],
            ['name' => 'Probe', 'type' => QuestStepType::PROBE->value, 'config' => ['targets' => [
                ['value' => 'https://monad.dubec.dev/m/MONAD-FP-15', 'label' => 'FP 15', 'room' => 'library-open', 'kind' => 'card'],
                ['value' => 'https://monad.dubec.dev/d/monad02', 'label' => 'monad02', 'room' => 'library-open', 'kind' => 'node'],
            ]]],
        ]);

        // The folded key matches whatever spelling the route uses: bare code, URL, trailing slash.
        self::assertSame([], QuestSpecMapper::routeViolations([['MONAD-FP-15', 'monad02']], $steps));
        self::assertSame([], QuestSpecMapper::routeViolations([['https://monad.dubec.dev/m/monad-fp-15/']], $steps));

        self::assertSame(
            ['Route 1 names "MONAD-FP-99", which no probe step in this quest accepts.'],
            QuestSpecMapper::routeViolations([['MONAD-FP-99']], $steps),
        );
    }

    public function testEveryRouteIsCheckedAndNumberedFromOne(): void
    {
        self::assertSame([
            'Route 1 names "a", which no probe step in this quest accepts.',
            'Route 2 names "b", which no probe step in this quest accepts.',
        ], QuestSpecMapper::routeViolations([['a'], ['b']], []));
    }
}
