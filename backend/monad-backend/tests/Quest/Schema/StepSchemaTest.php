<?php

namespace App\Tests\Quest\Schema;

use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use App\Quest\Schema\ConnectToApSchema;
use App\Quest\Schema\FieldSpec;
use App\Quest\Schema\StepSchemaRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * One schema per step type (IP-157), and every rule ValidStepConfigValidator carried before,
 * exercised through the registry rather than the Validator component.
 *
 * The payload test at the end is the contract against the quests that run today: the six
 * `infra/lab/quests/*-2026-09.json` files in the monad-knowledge checkout must validate with
 * zero violations, so a schema that drifts from the app's parser is caught before it reaches a
 * phone. Skipped, not failed, when this backend is checked out on its own.
 */
class StepSchemaTest extends TestCase
{
    private StepSchemaRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new StepSchemaRegistry();
    }

    /** @return list<string> */
    private function violations(string $type, array $config): array
    {
        return $this->registry->for(QuestStepType::from($type))->validate($config);
    }

    // ── registry ────────────────────────────────────────────────────────────────────────────

    public function testEveryEnumCaseHasASchemaAndAPalette(): void
    {
        $seen = [];
        foreach ($this->registry->all() as $schema) {
            $seen[] = $schema->type()->value;
            $palette = $schema->palette();
            self::assertNotSame('', $palette['title']);
            self::assertNotSame('', $palette['summary']);
            self::assertArrayHasKey('disabled_reason', $palette);
            foreach ($schema->fields() as $field) {
                self::assertInstanceOf(FieldSpec::class, $field);
                self::assertContains($field->kind, FieldSpec::KINDS);
            }
            // An empty config may be rejected; it must never throw.
            $schema->validate([]);
        }
        self::assertSame(array_map(static fn (QuestStepType $t) => $t->value, QuestStepType::cases()), $seen);
    }

    public function testOnlyConnectToApIsDisabledOnThisFleet(): void
    {
        foreach ($this->registry->all() as $schema) {
            $reason = $schema->palette()['disabled_reason'];
            if ($schema->type() === QuestStepType::CONNECT_TO_AP) {
                self::assertSame('No access point on this fleet; a connect_to_ap step blocks a run.', $reason);
                self::assertSame(ConnectToApSchema::DISABLED_REASON, $reason);
            } else {
                self::assertNull($reason, $schema->type()->value);
            }
        }
    }

    public function testRequiredFieldsPerType(): void
    {
        $required = [];
        foreach ($this->registry->all() as $schema) {
            $required[$schema->type()->value] = array_values(array_map(
                static fn (FieldSpec $f) => $f->name,
                array_filter($schema->fields(), static fn (FieldSpec $f) => $f->required),
            ));
        }

        self::assertSame([
            'start' => [],
            'wait' => ['timeout_seconds'],
            'scan_qr' => ['expected_value', 'location'],
            'connect_to_ap' => ['ap_id'],
            'walk_to' => [],
            'find_ble_device' => ['device_name'],
            'sensor_capture' => ['module'],
            'ble_advertise' => ['duration_seconds'],
            'probe' => ['dwell_seconds', 'targets'],
            'observe' => ['prompt', 'min_readings'],
            'finish' => [],
        ], $required);
    }

    // ── start / finish ──────────────────────────────────────────────────────────────────────

    public function testStartAndFinishAcceptAnEmptyConfig(): void
    {
        self::assertSame([], $this->violations('start', []));
        self::assertSame([], $this->violations('finish', []));
    }

    public function testStartAcceptsTheFourFeatureFlags(): void
    {
        self::assertSame([], $this->violations('start', [
            'features' => ['broadcast' => true, 'track' => false, 'witness' => false, 'illuminator' => false],
            'description' => 'Read this first.',
        ]));
    }

    public function testStartRejectsANonBooleanFeatureFlag(): void
    {
        $messages = $this->violations('start', ['features' => ['broadcast' => 'yes']]);
        self::assertCount(1, $messages);
        self::assertStringContainsString('features.broadcast', $messages[0]);
        self::assertStringContainsString('a boolean', $messages[0]);
    }

    public function testStartRejectsANonObjectFeaturesBlock(): void
    {
        self::assertNotEmpty($this->violations('start', ['features' => 'broadcast']));
    }

    public function testDescriptionMustBeAStringWhenPresent(): void
    {
        self::assertNotEmpty($this->violations('finish', ['description' => 42]));
    }

    // ── wait ────────────────────────────────────────────────────────────────────────────────

    public function testWaitRequiresAPositiveTimeout(): void
    {
        self::assertSame(
            ['Missing required field "timeout_seconds" for step type "wait".'],
            $this->violations('wait', []),
        );
        self::assertNotEmpty($this->violations('wait', ['timeout_seconds' => 0]));
        self::assertNotEmpty($this->violations('wait', ['timeout_seconds' => '30']));
        self::assertSame([], $this->violations('wait', ['timeout_seconds' => 30]));
    }

    // ── scan_qr ─────────────────────────────────────────────────────────────────────────────

    public function testScanQrRequiresExpectedValueAndLocation(): void
    {
        $messages = $this->violations('scan_qr', []);
        self::assertCount(2, $messages);
        self::assertStringContainsString('expected_value', $messages[0]);
        self::assertStringContainsString('location', $messages[1]);
        self::assertNotEmpty($this->violations('scan_qr', ['expected_value' => '  ', 'location' => 'door']));
        self::assertSame([], $this->violations('scan_qr', ['expected_value' => 'MONAD-01', 'location' => 'door']));
    }

    // ── connect_to_ap ───────────────────────────────────────────────────────────────────────

    public function testConnectToApRequiresApIdAndForbidsCredentials(): void
    {
        self::assertNotEmpty($this->violations('connect_to_ap', []));
        self::assertSame([], $this->violations('connect_to_ap', ['ap_id' => 'lab-ap']));
        self::assertNotEmpty($this->violations('connect_to_ap', ['ap_id' => 'lab-ap', 'password' => 'hunter2']));
        self::assertNotEmpty($this->violations('connect_to_ap', ['ap_id' => 'lab-ap', 'ssid' => 'monad-lab']));
    }

    // ── walk_to ─────────────────────────────────────────────────────────────────────────────

    public function testWalkToNeedsALocationOrCoordinates(): void
    {
        self::assertNotEmpty($this->violations('walk_to', []));
        self::assertSame([], $this->violations('walk_to', ['location' => 'library-open']));
        self::assertSame([], $this->violations('walk_to', ['latitude' => 48.15, 'longitude' => 17.11, 'radius' => 5]));
        self::assertNotEmpty($this->violations('walk_to', ['latitude' => 91, 'longitude' => 17.11]));
        self::assertNotEmpty($this->violations('walk_to', ['latitude' => 48.15, 'longitude' => 181]));
        self::assertNotEmpty($this->violations('walk_to', ['location' => 'x', 'radius' => 0]));
    }

    // ── find_ble_device ─────────────────────────────────────────────────────────────────────

    public function testFindBleDeviceRequiresANameAndChecksTheMac(): void
    {
        self::assertNotEmpty($this->violations('find_ble_device', []));
        self::assertSame([], $this->violations('find_ble_device', ['device_name' => 'anchor-3']));
        self::assertSame([], $this->violations('find_ble_device', [
            'device_name' => 'anchor-3', 'device_id' => 'AA:BB:CC:DD:EE:FF', 'rssi_threshold' => -70, 'detection_duration' => 5,
        ]));
        $messages = $this->violations('find_ble_device', ['device_name' => 'anchor-3', 'device_id' => 'not-a-mac']);
        self::assertCount(1, $messages);
        self::assertStringContainsString('MAC address', $messages[0]);
        self::assertNotEmpty($this->violations('find_ble_device', ['device_name' => 'a', 'rssi_threshold' => '-70']));
        self::assertNotEmpty($this->violations('find_ble_device', ['device_name' => 'a', 'detection_duration' => 0]));
    }

    // ── sensor_capture ──────────────────────────────────────────────────────────────────────

    public function testSensorCaptureRequiresModule(): void
    {
        self::assertNotEmpty($this->violations('sensor_capture', []));
        self::assertSame([], $this->violations('sensor_capture', ['module' => 'room-scan', 'anything' => 'opaque']));
    }

    // ── ble_advertise ───────────────────────────────────────────────────────────────────────

    public function testBleAdvertiseRules(): void
    {
        $messages = $this->violations('ble_advertise', []);
        self::assertStringContainsString('duration_seconds', $messages[0]);
        self::assertSame([], $this->violations('ble_advertise', ['duration_seconds' => 120]));
        self::assertSame([], $this->violations('ble_advertise', ['duration_seconds' => 300, 'adv_interval_ms' => 250, 'tx_power' => 'medium']));
        foreach ([99, 10241] as $interval) {
            self::assertNotEmpty($this->violations('ble_advertise', ['duration_seconds' => 60, 'adv_interval_ms' => $interval]));
        }
        self::assertNotEmpty($this->violations('ble_advertise', ['duration_seconds' => 60, 'tx_power' => 'maximum']));
    }

    // ── probe ───────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function probeTarget(array $overrides = []): array
    {
        return $overrides + [
            'value' => 'https://monad.dubec.dev/m/MONAD-FP-07',
            'label' => 'Fingerprint point 07',
            'room' => 'library-open',
            'kind' => 'card',
        ];
    }

    public function testProbeRules(): void
    {
        self::assertSame([], $this->violations('probe', ['dwell_seconds' => 30, 'targets' => [self::probeTarget()]]));
        self::assertSame([], $this->violations('probe', ['dwell_seconds' => 30, 'targets' => [
            self::probeTarget(),
            self::probeTarget(['value' => 'https://monad.dubec.dev/d/monad04', 'label' => 'Node monad04', 'kind' => 'node']),
        ]]));

        $messages = $this->violations('probe', ['targets' => [self::probeTarget()]]);
        self::assertStringContainsString('dwell_seconds', $messages[0]);

        self::assertSame(
            ['Missing required field "targets" for step type "probe".'],
            $this->violations('probe', ['dwell_seconds' => 30]),
        );
        self::assertNotEmpty($this->violations('probe', ['dwell_seconds' => 30, 'targets' => []]));
        self::assertNotEmpty($this->violations('probe', ['dwell_seconds' => 30, 'targets' => ['MONAD-FP-07']]));

        $untagged = self::probeTarget();
        unset($untagged['kind']);
        self::assertNotEmpty($this->violations('probe', ['dwell_seconds' => 30, 'targets' => [$untagged]]));
        self::assertNotEmpty($this->violations('probe', ['dwell_seconds' => 30, 'targets' => [self::probeTarget(['kind' => 'beacon'])]]));

        $roomless = self::probeTarget();
        unset($roomless['room']);
        self::assertNotEmpty($this->violations('probe', ['dwell_seconds' => 30, 'targets' => [$roomless]]));
    }

    // ── observe ─────────────────────────────────────────────────────────────────────────────

    public function testObserveRules(): void
    {
        self::assertSame([], $this->violations('observe', ['prompt' => 'How many?', 'min_readings' => 5]));
        self::assertNotEmpty($this->violations('observe', ['min_readings' => 5]));
        $messages = $this->violations('observe', ['prompt' => 'How many?']);
        self::assertStringContainsString('min_readings', $messages[0]);
        self::assertNotEmpty($this->violations('observe', ['prompt' => 'How many?', 'min_readings' => 0]));
        self::assertSame([], $this->violations('observe', ['prompt' => 'How many?', 'min_readings' => 3, 'max_count' => 60]));
        self::assertNotEmpty($this->violations('observe', ['prompt' => 'How many?', 'min_readings' => 3, 'max_count' => 0]));
    }

    // ── the entity path ─────────────────────────────────────────────────────────────────────

    public function testTheConstraintNowFiresOnTheEntity(): void
    {
        // The whole point of IP-157's schema move: an observe step without min_readings is
        // refused on QuestStep itself, which is what the admin form and lab_quest_write validate.
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $step = (new QuestStep())->setName('Count')->setType(QuestStepType::OBSERVE)->setOrder(1)
            ->setConfig(['prompt' => 'How many?']);
        $violations = $validator->validateProperty($step, 'config');
        self::assertCount(1, $violations);
        self::assertSame('Missing required field "min_readings" for step type "observe".', (string) $violations[0]->getMessage());

        $step->setConfig(['prompt' => 'How many?', 'min_readings' => 5]);
        self::assertCount(0, $validator->validateProperty($step, 'config'));
    }

    // ── the quests that run today ───────────────────────────────────────────────────────────

    public function testTheLiveQuestPayloadsValidateWithZeroViolations(): void
    {
        // dirname(__DIR__, 3) is the app root (backend/monad-backend); four levels above it is
        // the monad-knowledge checkout: backend -> repos/monad-backend -> repos -> monad-knowledge.
        $dir = dirname(__DIR__, 3) . '/../../../../infra/lab/quests';
        $files = is_dir($dir) ? glob($dir . '/*-2026-09.json') : [];
        if ($files === [] || $files === false) {
            self::markTestSkipped('infra/lab/quests/*-2026-09.json not found beside this checkout (monad-knowledge layout only).');
        }

        self::assertCount(6, $files, 'six live quest payloads were published on 2026-09-15');

        // KNOWN DEFECT, recorded rather than hidden (2026-09-16). presence-2026-09.json step 1 is
        // a probe with `targets: []` and a `durations_minutes` list; its README calls the empty
        // list intentional ("the study timer"). The app disagrees: ProbeStep.kt renders an
        // empty-target probe as "This step has nothing to scan for. It cannot run — tell the
        // operator." and nothing in the app reads durations_minutes. The schema keeps the rule
        // the validator always had (a probe needs at least one target), so this file is expected
        // to produce exactly this one violation until the quest is re-authored. When it is, this
        // assertion fails on purpose: delete the exception.
        $known = [
            'presence-2026-09.json' => [
                1 => ['Field "targets" has invalid value for step type "probe": must be a non-empty list of targets.'],
            ],
        ];

        foreach ($files as $file) {
            $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload['steps'] ?? null, basename($file));
            foreach ($payload['steps'] as $i => $step) {
                $messages = $this->violations($step['type'], (array) ($step['config'] ?? []));
                $expected = $known[basename($file)][$i] ?? [];
                self::assertSame($expected, $messages, sprintf('%s step %d (%s)', basename($file), $i, $step['type']));
            }
        }
    }
}
