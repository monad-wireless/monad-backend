<?php

declare(strict_types=1);

namespace App\Tests\Quest\Live;

use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use App\Quest\Live\LiveEngineSpecs;
use App\Quest\QuestPreflight;
use App\Quest\Schema\StepSchemaRegistry;
use App\Service\MarkerService;
use PHPUnit\Framework\TestCase;

/** Authoring a live step: what is refused, what it requires of a phone, which cards it names. */
final class LiveSchemaTest extends TestCase
{
    public const TARGET = ['value' => 'https://monad.dubec.dev/m/MONAD-FP-01', 'label' => 'Point 01', 'room' => 'library-open', 'kind' => 'card'];

    /** @param array<string, mixed> $config @return list<string> */
    private function violations(array $config): array
    {
        return (new StepSchemaRegistry())->for(QuestStepType::LIVE)->validate($config);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private static function hunt(array $options = []): array
    {
        return ['engine' => 'radio_hunt', 'options' => $options + ['floor' => 'f', 'targets' => [self::TARGET], 'rounds' => 1]];
    }

    public function testTheSmallestHuntIsValid(): void
    {
        self::assertSame([], $this->violations(self::hunt()));
        self::assertSame([], $this->violations(self::hunt(['guidance' => ['position' => 2, 'pattern' => 1, 'none' => 0], 'count_question' => null])));
    }

    public function testBrokenHuntsAreRefused(): void
    {
        foreach ([
            ['engine' => 'nope'],
            ['engine' => 'radio_hunt'],
            ['engine' => 'radio_hunt', 'options' => 'x'],
            self::hunt(['rounds' => 2]),
            self::hunt(['rounds' => 0]),
            self::hunt(['targets' => [self::TARGET, self::TARGET], 'rounds' => 1]),
            self::hunt(['targets' => [['kind' => 'shelf'] + self::TARGET]]),
            self::hunt(['guidance' => ['telepathy' => 1]]),
            self::hunt(['guidance' => ['position' => 0]]),
            self::hunt(['zone_radius_m' => 'near']),
        ] as $config) {
            self::assertNotSame([], $this->violations($config), json_encode($config));
        }
    }

    public function testQuestionnairesAreChecked(): void
    {
        $ok = ['engine' => 'questions', 'options' => ['questions' => [
            ['id' => 'n', 'prompt' => 'How many?', 'kind' => 'number', 'min' => 0, 'max' => 9],
            ['id' => 'c', 'prompt' => 'Which?', 'kind' => 'choice', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
        ]]];
        self::assertSame([], $this->violations($ok));
        $ok['options']['questions'][1]['id'] = 'n';
        self::assertNotSame([], $this->violations($ok));
    }

    public function testPreflightDerivesTheCapabilitiesAndAsksForTheBroadcast(): void
    {
        $steps = [
            ['name' => 'Start', 'type' => 'start', 'config' => ['features' => ['broadcast' => true]]],
            ['name' => 'Hunt', 'type' => 'live', 'config' => self::hunt()],
        ];
        $on = (new QuestPreflight())->run(['steps' => $steps], []);
        self::assertEqualsCanonicalizing(['ble.advertise', 'quest.live.v1', 'camera.qr'], $on['required_capabilities']);
        self::assertSame([], $on['warnings']);

        $steps[0]['config'] = [];
        $off = (new QuestPreflight())->run(['steps' => $steps], []);
        self::assertStringContainsString('needs the phone on air', implode(' ', $off['warnings']));
    }

    public function testAQuestThatNeedsTheContractIsWithheldFromASilentHandset(): void
    {
        $quest = (new Quest())->setRequiredCapabilities(['ble.advertise', 'quest.live.v1']);

        self::assertTrue($quest->requiresStrictContract());
        self::assertFalse($quest->satisfiesStrictContracts([]));
        self::assertFalse($quest->satisfiesStrictContracts(['ble.advertise']));
        self::assertTrue($quest->satisfiesStrictContracts(['quest.live.v1']));
        self::assertTrue((new Quest())->setRequiredCapabilities(['ble.advertise'])->satisfiesStrictContracts([]));
    }

    public function testAHuntNamesItsCards(): void
    {
        $step = (new QuestStep())->setType(QuestStepType::LIVE)->setName('Hunt')->setOrder(1)->setConfig(self::hunt());

        self::assertSame([self::TARGET['value'] => 'Point 01'], MarkerService::scannedValues($step));
        self::assertSame(['radio_hunt', 'questions'], array_keys(LiveEngineSpecs::all()));
    }
}
