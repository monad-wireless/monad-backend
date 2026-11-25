<?php

declare(strict_types=1);

namespace App\Tests\Quest\Live;

use App\Quest\Live\Guidance\NoGuide;
use App\Quest\Live\Guidance\PatternGuide;
use App\Quest\Live\Guidance\PositionGuide;
use App\Quest\Live\Guidance\RadioModel;
use PHPUnit\Framework\TestCase;

/** The PHP port against `lab_atlas.py` on real windows of the 28 August walk. */
final class RadioModelTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $doc;
    private RadioModel $model;

    protected function setUp(): void
    {
        $this->doc = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/live/radio-model.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->model = new RadioModel($this->doc['nodes'], $this->doc['p0_dbm'], $this->doc['exponent']);
    }

    public function testItLocatesWhereThePythonSideDoes(): void
    {
        self::assertGreaterThan(30, count($this->doc['cases']));
        foreach ($this->doc['cases'] as $i => $case) {
            $at = $this->model->locate($this->model->observations($case['medians']));
            self::assertNotNull($at);
            self::assertEqualsWithDelta($case['position'][0], $at[0], 0.01, "case $i x");
            self::assertEqualsWithDelta($case['position'][1], $at[1], 0.01, "case $i y");
        }
    }

    public function testTheMismatchMatchesAndIgnoresTheHandsetsPower(): void
    {
        foreach ($this->doc['cases'] as $case) {
            $observations = $this->model->observations($case['medians']);
            self::assertEqualsWithDelta($case['mismatch_at_10_10'], $this->model->mismatch($observations, 10.0, 10.0), 1e-5);
            $louder = array_map(static fn (array $o): array => [$o[0], $o[1], $o[2] + 9.0], $observations);
            self::assertEqualsWithDelta($case['mismatch_at_10_10'], $this->model->mismatch($louder, 10.0, 10.0), 1e-5);
        }
    }

    public function testFewerThanThreeNodesSayNothing(): void
    {
        $two = [['node' => 'monad02', 'rssi' => -60.0], ['node' => 'monad03', 'rssi' => -70.0], ['node' => 'unknown', 'rssi' => -50.0]];
        $observations = $this->model->observations($two);

        self::assertCount(2, $observations);
        self::assertNull($this->model->locate($observations));
        self::assertNull((new PositionGuide())->hint($this->model, $observations, [1.0, 1.0], [], false));
        self::assertNull((new PatternGuide())->hint($this->model, $observations, [1.0, 1.0], [], false));
    }

    public function testThePositionGuideKeepsAZoneUntilThePlayerClearlyLeaves(): void
    {
        $observations = $this->model->observations($this->doc['cases'][0]['medians']);
        [$x, $y] = $this->doc['cases'][0]['position'];
        $guide = new PositionGuide(4.0);

        self::assertTrue($guide->hint($this->model, $observations, [$x + 3.0, $y], [], false)->inZone);
        self::assertFalse($guide->hint($this->model, $observations, [$x + 5.0, $y], [], false)->inZone);
        self::assertTrue($guide->hint($this->model, $observations, [$x + 5.0, $y], [], true)->inZone);
        self::assertGreaterThan(
            $guide->hint($this->model, $observations, [$x + 10.0, $y], [], false)->closeness,
            $guide->hint($this->model, $observations, [$x + 2.0, $y], [], false)->closeness,
        );
    }

    public function testTheControlArmNeverHints(): void
    {
        $observations = $this->model->observations($this->doc['cases'][0]['medians']);

        self::assertNull((new NoGuide())->hint($this->model, $observations, [1.0, 1.0], [], false));
    }
}
