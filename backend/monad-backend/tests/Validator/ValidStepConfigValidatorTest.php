<?php

namespace App\Tests\Validator;

use App\Dto\Quest\QuestCreateStepDto;
use App\Enum\QuestStepType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The step-config validator dispatches on QuestStepType with a `match` and no default arm.
 *
 * That shape is deliberate — adding an enum case without a validation arm should be loud — but
 * before these tests it was loud in production: `sensor_capture` was added to the enum, left out
 * of the match, and every request reaching it would have been a 500 (`\UnhandledMatchError`). The
 * exhaustiveness test below turns that failure into a red test at the moment the case is added.
 */
class ValidStepConfigValidatorTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    private function dto(string $type, array $config): QuestCreateStepDto
    {
        $dto = new QuestCreateStepDto();
        $dto->name = 'Step under test';
        $dto->type = $type;
        $dto->order = 0;
        $dto->config = $config;

        return $dto;
    }

    /** @return list<string> */
    private function violationMessages(string $type, array $config): array
    {
        $violations = $this->validator->validate($this->dto($type, $config));

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    public function testEveryEnumCaseHasAValidatorArm(): void
    {
        foreach (QuestStepType::cases() as $case) {
            // An empty config may produce violations; it must never throw. A missing match arm
            // throws \UnhandledMatchError, which is exactly the regression this guards against.
            $this->validator->validate($this->dto($case->value, []));
        }

        $this->addToAssertionCount(1);
    }

    public function testEveryEnumCaseIsCreatable(): void
    {
        foreach (QuestStepType::cases() as $case) {
            self::assertContains(
                $case->value,
                QuestCreateStepDto::VALID_STEP_TYPES,
                sprintf('"%s" exists in the enum but the create DTO rejects it.', $case->value),
            );
        }
    }

    public function testSensorCaptureRequiresModule(): void
    {
        self::assertNotEmpty($this->violationMessages('sensor_capture', []));
        self::assertSame([], $this->violationMessages('sensor_capture', ['module' => 'room-scan']));
    }

    public function testBleAdvertiseRequiresDuration(): void
    {
        $messages = $this->violationMessages('ble_advertise', []);
        self::assertNotEmpty($messages);
        self::assertStringContainsString('duration_seconds', $messages[0]);
    }

    public function testBleAdvertiseAcceptsAMinimalConfig(): void
    {
        self::assertSame([], $this->violationMessages('ble_advertise', ['duration_seconds' => 120]));
    }

    public function testBleAdvertiseAcceptsAFullConfig(): void
    {
        self::assertSame([], $this->violationMessages('ble_advertise', [
            'duration_seconds' => 300,
            'adv_interval_ms' => 250,
            'tx_power' => 'medium',
        ]));
    }

    public function testBleAdvertiseRejectsAnIntervalOutsideTheBleBounds(): void
    {
        foreach ([99, 10241] as $interval) {
            $messages = $this->violationMessages('ble_advertise', [
                'duration_seconds' => 60,
                'adv_interval_ms' => $interval,
            ]);
            self::assertNotEmpty($messages, sprintf('interval %d must be rejected', $interval));
        }
    }

    public function testBleAdvertiseRejectsAnUnknownTxPower(): void
    {
        $messages = $this->violationMessages('ble_advertise', [
            'duration_seconds' => 60,
            'tx_power' => 'maximum',
        ]);
        self::assertNotEmpty($messages);
    }

    // ── probe (IP-140) ──────────────────────────────────────────────────────────────────────

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

    public function testProbeAcceptsAGeneratedTarget(): void
    {
        self::assertSame([], $this->violationMessages('probe', [
            'dwell_seconds' => 30,
            'targets' => [self::probeTarget()],
        ]));
    }

    public function testProbeAcceptsManyTargets(): void
    {
        self::assertSame([], $this->violationMessages('probe', [
            'dwell_seconds' => 30,
            'targets' => [
                self::probeTarget(),
                self::probeTarget([
                    'value' => 'https://monad.dubec.dev/d/monad04',
                    'label' => 'Node monad04',
                    'kind' => 'node',
                ]),
            ],
        ]));
    }

    public function testProbeRequiresADwell(): void
    {
        $messages = $this->violationMessages('probe', ['targets' => [self::probeTarget()]]);
        self::assertNotEmpty($messages);
        self::assertStringContainsString('dwell_seconds', $messages[0]);
    }

    public function testProbeRejectsAnEmptyTargetList(): void
    {
        // A probe with nothing to match is a card a participant stands in front of forever.
        self::assertNotEmpty($this->violationMessages('probe', [
            'dwell_seconds' => 30,
            'targets' => [],
        ]));
    }

    public function testProbeRejectsAnUntaggedTarget(): void
    {
        // The kind is not decoration. A dwell at a node sits at zero distance from one end of
        // every link that node terminates; a dwell at a card samples open floor. Pooling the two
        // produces a statistic nobody can interpret, so the tag is mandatory.
        $target = self::probeTarget();
        unset($target['kind']);

        self::assertNotEmpty($this->violationMessages('probe', [
            'dwell_seconds' => 30,
            'targets' => [$target],
        ]));
    }

    public function testProbeRejectsAnUnknownKind(): void
    {
        self::assertNotEmpty($this->violationMessages('probe', [
            'dwell_seconds' => 30,
            'targets' => [self::probeTarget(['kind' => 'beacon'])],
        ]));
    }

    public function testProbeRejectsATargetWithNoRoom(): void
    {
        // A target with no room resolves to a scan that names no place, which is the whole
        // difference between a probe and a scan_qr.
        $target = self::probeTarget();
        unset($target['room']);

        self::assertNotEmpty($this->violationMessages('probe', [
            'dwell_seconds' => 30,
            'targets' => [$target],
        ]));
    }

    // ── observe (IP-140): the human headcount ───────────────────────────────────────────────

    public function testObserveAcceptsAMinimalConfig(): void
    {
        self::assertSame([], $this->violationMessages('observe', [
            'prompt' => 'How many people can you see right now?',
            'min_readings' => 5,
        ]));
    }

    public function testObserveRequiresAPrompt(): void
    {
        // The step is a question. Without one the widget shows a number and no
        // reason to touch it, and two participants answer two different questions.
        self::assertNotEmpty($this->violationMessages('observe', ['min_readings' => 5]));
    }

    public function testObserveRequiresAReadingCountWithNoDefault(): void
    {
        // A count step that silently accepts one reading and completes is the
        // difference between a measurement and an anecdote. The author has to say.
        $messages = $this->violationMessages('observe', ['prompt' => 'How many?']);
        self::assertNotEmpty($messages);
        self::assertStringContainsString('min_readings', $messages[0]);
    }

    public function testObserveRejectsZeroReadings(): void
    {
        self::assertNotEmpty($this->violationMessages('observe', [
            'prompt' => 'How many?',
            'min_readings' => 0,
        ]));
    }

    public function testObserveAcceptsAnOptionalCeiling(): void
    {
        // Optional on purpose: a room whose capacity nobody has stated must not
        // get a fabricated one from this validator.
        self::assertSame([], $this->violationMessages('observe', [
            'prompt' => 'How many?',
            'min_readings' => 3,
            'max_count' => 60,
        ]));
        self::assertNotEmpty($this->violationMessages('observe', [
            'prompt' => 'How many?',
            'min_readings' => 3,
            'max_count' => 0,
        ]));
    }

    // ── connect_to_ap (IP-140): the credential is the bundle's, never the quest's ───────────

    public function testConnectToApRejectsAnAuthoredPassword(): void
    {
        // Step config is served to every authenticated caller, so a password here is published.
        $messages = $this->violationMessages('connect_to_ap', [
            'ap_id' => 'lab-ap',
            'password' => 'hunter2',
        ]);
        self::assertNotEmpty($messages);
    }

    public function testConnectToApRejectsAnAuthoredSsid(): void
    {
        self::assertNotEmpty($this->violationMessages('connect_to_ap', [
            'ap_id' => 'lab-ap',
            'ssid' => 'monad-lab',
        ]));
    }

    public function testConnectToApAcceptsABundleReference(): void
    {
        self::assertSame([], $this->violationMessages('connect_to_ap', ['ap_id' => 'lab-ap']));
    }
}
