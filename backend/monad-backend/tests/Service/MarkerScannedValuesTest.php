<?php

namespace App\Tests\Service;

use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use App\Service\MarkerService;
use PHPUnit\Framework\TestCase;

/**
 * The marker index is a projection of the quest set, and IP-140 gave it a second source.
 *
 * Before the probe step there was one rule: a marker is a `scan_qr` step's `expected_value`. A
 * probe names a *list* of targets instead, so a projection that still read only `expected_value`
 * would drop every card a probe accepts — silently, and with no symptom in the app, because the
 * app matches the same list it was given. The symptom would appear on `/m/<code>`: a participant
 * holding a working card is told the marker is unknown.
 *
 * These tests pin the rule as a pure function of one step. `markers()` needs a database; this is
 * the half that decides what a marker *is*.
 */
class MarkerScannedValuesTest extends TestCase
{
    private static function step(QuestStepType $type, string $name, array $config): QuestStep
    {
        $step = new QuestStep();
        $step->setType($type);
        $step->setName($name);
        $step->setConfig($config);

        return $step;
    }

    public function testScanQrProjectsItsExpectedValueUnderTheStepName(): void
    {
        $step = self::step(QuestStepType::SCAN_QR, 'Check in', [
            'expected_value' => 'https://monad.dubec.dev/m/MONAD-A-IN',
        ]);

        self::assertSame(
            ['https://monad.dubec.dev/m/MONAD-A-IN' => 'Check in'],
            MarkerService::scannedValues($step),
        );
    }

    public function testScanQrWithNoExpectedValueIsNotAMarker(): void
    {
        // Such a step matches any code at all. That is an authoring mistake, but it is not a
        // physical card, so it must never reach a printable sheet.
        $step = self::step(QuestStepType::SCAN_QR, 'Broken', []);

        self::assertSame([], MarkerService::scannedValues($step));
    }

    public function testProbeProjectsEveryTargetUnderItsOwnLabel(): void
    {
        // One probe legitimately accepts twenty cards, so the *target's* label is the marker's
        // label. Falling back to the step name would print "Find a point" on twenty sheets.
        $step = self::step(QuestStepType::PROBE, 'Find a point and hold still', [
            'dwell_seconds' => 30,
            'targets' => [
                ['value' => 'https://monad.dubec.dev/m/MONAD-FP-07', 'label' => 'Fingerprint point 07', 'room' => 'library-open', 'kind' => 'card'],
                ['value' => 'https://monad.dubec.dev/d/monad04', 'label' => 'Node monad04', 'room' => 'library-open', 'kind' => 'node'],
            ],
        ]);

        self::assertSame(
            [
                'https://monad.dubec.dev/m/MONAD-FP-07' => 'Fingerprint point 07',
                'https://monad.dubec.dev/d/monad04' => 'Node monad04',
            ],
            MarkerService::scannedValues($step),
        );
    }

    public function testProbeFallsBackToTheStepNameWhenATargetHasNoLabel(): void
    {
        $step = self::step(QuestStepType::PROBE, 'Find a point', [
            'dwell_seconds' => 30,
            'targets' => [['value' => 'MONAD-FP-01', 'room' => 'library-open', 'kind' => 'card']],
        ]);

        self::assertSame(['MONAD-FP-01' => 'Find a point'], MarkerService::scannedValues($step));
    }

    public function testProbeSkipsABlankTargetValue(): void
    {
        $step = self::step(QuestStepType::PROBE, 'Find a point', [
            'dwell_seconds' => 30,
            'targets' => [
                ['value' => '   ', 'label' => 'Nothing', 'room' => 'library-open', 'kind' => 'card'],
                ['value' => 'MONAD-FP-02', 'label' => 'Point 02', 'room' => 'library-open', 'kind' => 'card'],
            ],
        ]);

        self::assertSame(['MONAD-FP-02' => 'Point 02'], MarkerService::scannedValues($step));
    }

    public function testProbeWithNoTargetsProjectsNothing(): void
    {
        $step = self::step(QuestStepType::PROBE, 'Find a point', ['dwell_seconds' => 30]);

        self::assertSame([], MarkerService::scannedValues($step));
    }

    public function testStepTypesThatReachNoCardProjectNothing(): void
    {
        // Only two step types put a participant in front of a printed code. Everything else must
        // project nothing, or a sheet grows entries no card corresponds to.
        foreach (QuestStepType::cases() as $case) {
            if (in_array($case, [QuestStepType::SCAN_QR, QuestStepType::PROBE], true)) {
                continue;
            }

            $step = self::step($case, 'Some step', ['expected_value' => 'X', 'targets' => [['value' => 'Y']]]);
            self::assertSame(
                [],
                MarkerService::scannedValues($step),
                sprintf('"%s" must not project a marker.', $case->value),
            );
        }
    }
}
