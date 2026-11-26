<?php

declare(strict_types=1);

namespace App\Tests\Quest;

use App\Quest\QuestPreflight;
use PHPUnit\Framework\TestCase;

/**
 * The builder's preflight rules (IP-157 Phase 2), one rule per test.
 *
 * QuestPreflight is pure over arrays, so the "fakes" here are literals: a spec in the
 * `lab_quest_write` shape and a mirror that is a list of `{key, kind, room, synced_at}` rows the
 * caller read out of `lab_placements`. No container, no clock of its own, no repository — `now`
 * and the quest's `updated_at` are arguments, so every rule is deterministic.
 *
 * A warning never blocks a save. What blocks is a step schema violation or a route that names a
 * key no probe accepts, and those are QuestSpecMapper's, tested next door.
 */
final class QuestPreflightTest extends TestCase
{
    private QuestPreflight $preflight;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->preflight = new QuestPreflight();
        $this->now = new \DateTimeImmutable('2026-09-17T12:00:00+00:00');
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @param list<array<string, mixed>> $mirror
     * @return list<string>
     */
    private function warnings(array $steps, array $mirror = [], array $header = [], ?\DateTimeInterface $updatedAt = null): array
    {
        return $this->preflight->run($header + ['steps' => $steps], $mirror, $updatedAt, $this->now)['warnings'];
    }

    /** @param list<array<string, mixed>> $steps @return list<string> */
    private function capabilities(array $steps): array
    {
        return $this->preflight->run(['steps' => $steps], [], null, $this->now)['required_capabilities'];
    }

    private static function target(string $value, string $kind = 'card', string $room = 'library-open'): array
    {
        return ['value' => $value, 'label' => $value, 'room' => $room, 'kind' => $kind];
    }

    private static function row(string $key, string $kind = 'card', ?string $syncedAt = '2026-09-17T09:00:00+00:00'): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'room' => 'library-open',
            'synced_at' => $syncedAt === null ? null : new \DateTimeImmutable($syncedAt),
        ];
    }

    private static function start(bool $broadcast = false, bool $track = false): array
    {
        return ['name' => 'Before you start', 'type' => 'start', 'config' => [
            'features' => ['broadcast' => $broadcast, 'track' => $track],
        ]];
    }

    private static function probe(array $targets): array
    {
        return ['name' => 'Probe', 'type' => 'probe', 'config' => ['dwell_seconds' => 30, 'targets' => $targets]];
    }

    // ── an empty quest warns about nothing ──────────────────────────────────────────────────

    public function testAQuestWithNothingRemarkableWarnsAboutNothing(): void
    {
        self::assertSame([], $this->warnings([
            self::start(),
            ['name' => 'Run complete', 'type' => 'finish', 'config' => []],
        ]));
    }

    // ── the warnings lab_quest_write already emits ──────────────────────────────────────────

    public function testConnectToApWarnsThatTheRunWillAbort(): void
    {
        $warnings = $this->warnings([['name' => 'Join', 'type' => 'connect_to_ap', 'config' => ['ap_id' => 'lab-ap']]]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Step 0 asks the phone to join an access point', $warnings[0]);
        self::assertStringContainsString('the run will abort at that step', $warnings[0]);
    }

    public function testAScanWithNoExpectedValueMatchesAnyCode(): void
    {
        $warnings = $this->warnings([['name' => 'Scan', 'type' => 'scan_qr', 'config' => ['location' => 'door']]]);

        self::assertSame(['Step 0 is a scan with no expected_value: it matches any code.'], $warnings);
    }

    public function testBleAdvertiseWarnsAboutIosForegroundAndAddsTheCapability(): void
    {
        $steps = [['name' => 'Advertise', 'type' => 'ble_advertise', 'config' => ['duration_seconds' => 60]]];

        self::assertStringContainsString('iOS honours it only in the foreground', $this->warnings($steps)[0]);
        self::assertSame(['ble.advertise'], $this->capabilities($steps));
    }

    public function testAProbeWithNoTargetsCannotBeSatisfied(): void
    {
        $warnings = $this->warnings([self::start(true), self::probe([])]);

        self::assertSame(['Step 1 is a probe with no targets: nothing can satisfy it.'], $warnings);
    }

    public function testAnUntaggedProbeTargetIsWarnedAboutOncePerStep(): void
    {
        $bare = self::target('MONAD-FP-07');
        unset($bare['kind']);

        $warnings = $this->warnings([self::start(true), self::probe([$bare, $bare])], [self::row('MONAD-FP-07')]);

        self::assertCount(1, $warnings, 'one sentence per step, not one per target');
        self::assertStringContainsString('has a target with no kind (card|node)', $warnings[0]);
    }

    public function testAProbeQuestWithoutBroadcastRecordsNothingOnAir(): void
    {
        $warnings = $this->warnings([self::start(false), self::probe([self::target('MONAD-FP-07')])], [self::row('MONAD-FP-07')]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('does not declare features.broadcast', $warnings[0]);
    }

    public function testProbeAddsBothCapabilities(): void
    {
        self::assertSame(
            ['ble.advertise', 'camera.qr'],
            $this->capabilities([self::start(true), self::probe([self::target('MONAD-FP-07')])]),
        );
    }

    /**
     * The Counting quest's whole radio role is `features.broadcast`: no advertise step, no probe.
     * Deriving the capability from the step type alone left it declaring nothing, so a handset
     * that cannot hold the peripheral role was offered it and recorded counts with no position.
     */
    public function testASessionScopedBroadcastNeedsTheAdvertiseCapability(): void
    {
        self::assertSame(['ble.advertise'], $this->capabilities([
            self::start(true),
            ['name' => 'Count', 'type' => 'observe', 'config' => ['prompt' => 'How many?', 'min_readings' => 5]],
        ]));
    }

    public function testAQuestThatNeverGoesOnAirNeedsNoAdvertiseCapability(): void
    {
        self::assertSame([], $this->capabilities([
            self::start(false),
            ['name' => 'Count', 'type' => 'observe', 'config' => ['prompt' => 'How many?', 'min_readings' => 5]],
        ]));
    }

    /**
     * Only iOS implements a pose tracker; `PoseTracker.android.kt` is a stub. Before this token the
     * tracked survey route was kept off Android by `audience: operator` alone, which is a
     * convention rather than a gate — an operator on a borrowed Android would have walked twenty
     * stops and uploaded an empty pose stream.
     */
    public function testATrackedQuestNeedsThePoseCapability(): void
    {
        self::assertSame(
            // Start step first (broadcast, then track), probe second — the order the loop walks.
            ['ble.advertise', 'pose.track', 'camera.qr'],
            $this->capabilities([self::start(true, true), self::probe([self::target('MONAD-FP-07')])]),
        );
    }

    public function testTrackingIsNotGatedOnLidar(): void
    {
        // The mesh needs LiDAR; the trajectory does not. Requiring `lidar.mesh` would withhold the
        // survey from every non-Pro iPhone, which tracks on camera and IMU and exports no geometry.
        self::assertNotContains('lidar.mesh', $this->capabilities([self::start(true, true)]));
    }

    public function testAnUntrackedQuestNeedsNoPoseCapability(): void
    {
        self::assertSame(['ble.advertise'], $this->capabilities([self::start(true, false)]));
    }

    public function testARoomSweepStepNeedsTheSweepCapabilityAndABroadcast(): void
    {
        $sweep = ['type' => 'observe', 'name' => 'Count', 'config' => [
            'schema' => 'monad-quest/observe/v2',
            'mode' => 'room_sweep_cumulative',
            'prompt' => 'People counted so far',
        ]];
        $silent = $this->preflight->run(['steps' => [['type' => 'start', 'name' => 'Go', 'config' => []], $sweep]], [], null, $this->now);
        self::assertContains('observe.room_sweep.v1', $silent['required_capabilities']);
        self::assertNotEmpty(array_filter($silent['warnings'], static fn (string $w) => str_contains($w, 'room sweep')));

        $onAir = $this->preflight->run(
            ['steps' => [['type' => 'start', 'name' => 'Go', 'config' => ['features' => ['broadcast' => true]]], $sweep]],
            [], null, $this->now,
        );
        self::assertContains('observe.room_sweep.v1', $onAir['required_capabilities']);
        self::assertContains('ble.advertise', $onAir['required_capabilities']);
        self::assertEmpty(array_filter($onAir['warnings'], static fn (string $w) => str_contains($w, 'room sweep')));
    }

    public function testALegacyObserveStepNeedsNoSweepCapability(): void
    {
        $legacy = ['type' => 'observe', 'name' => 'Count', 'config' => ['prompt' => 'How many?', 'min_readings' => 5]];
        self::assertNotContains('observe.room_sweep.v1', $this->capabilities([$legacy]));
    }

    public function testCapabilitiesAreDeduplicatedAcrossSteps(): void
    {
        self::assertSame(['ble.advertise', 'camera.qr'], $this->capabilities([
            self::start(true),
            ['name' => 'Advertise', 'type' => 'ble_advertise', 'config' => ['duration_seconds' => 60]],
            self::probe([self::target('MONAD-FP-07')]),
            self::probe([self::target('MONAD-FP-08')]),
        ]));
    }

    // ── the checks the builder adds ─────────────────────────────────────────────────────────

    public function testBleAdvertiseInsideASessionThatAlreadyBroadcastsIsRedundant(): void
    {
        $warnings = $this->warnings([
            self::start(true),
            ['name' => 'Advertise', 'type' => 'ble_advertise', 'config' => ['duration_seconds' => 60]],
        ]);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('iOS honours it only in the foreground', $warnings[0]);
        self::assertStringContainsString('the start step already declares features.broadcast', $warnings[1]);
        self::assertStringContainsString('its labelled interval no longer equals the on-air one', $warnings[1]);
    }

    public function testATargetAbsentFromTheMirrorForThisFloorIsWarnedAbout(): void
    {
        $warnings = $this->warnings(
            [self::start(true), self::probe([self::target('MONAD-FP-07'), self::target('MONAD-FP-99')])],
            [self::row('MONAD-FP-07')],
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString('names target "MONAD-FP-99"', $warnings[0]);
        self::assertStringContainsString('not in the placement mirror for this floor', $warnings[0]);
    }

    public function testATargetIsMatchedByTheFoldedKeyNotTheSpelling(): void
    {
        // The handset, the portal and lab quest-check all fold a target the same way: lowercase,
        // query and fragment stripped, last path segment taken.
        self::assertSame([], $this->warnings(
            [self::start(true), self::probe([self::target('https://monad.dubec.dev/m/MONAD-FP-07?src=card')])],
            [self::row('monad-fp-07')],
        ));
    }

    public function testAMirrorRowOlderThanTheQuestIsWarnedAbout(): void
    {
        $warnings = $this->warnings(
            [self::start(true), self::probe([self::target('MONAD-FP-07')])],
            [self::row('MONAD-FP-07', syncedAt: '2026-09-10T09:00:00+00:00')],
            updatedAt: new \DateTimeImmutable('2026-09-16T10:00:00+00:00'),
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString('is older than this quest', $warnings[0]);
        self::assertStringContainsString('Re-run lab placements-export', $warnings[0]);
    }

    public function testAMirrorRowNewerThanTheQuestIsSilent(): void
    {
        self::assertSame([], $this->warnings(
            [self::start(true), self::probe([self::target('MONAD-FP-07')])],
            [self::row('MONAD-FP-07', syncedAt: '2026-09-17T09:00:00+00:00')],
            updatedAt: new \DateTimeImmutable('2026-09-16T10:00:00+00:00'),
        ));
    }

    public function testAnEmptyMirrorSaysSoOnceRatherThanOncePerTarget(): void
    {
        $warnings = $this->warnings(
            [self::start(true), self::probe([self::target('MONAD-FP-07'), self::target('MONAD-FP-08')])],
            [],
        );

        self::assertSame([
            'The placement mirror is empty: no placements synced yet, run lab placements-export. '
            . 'Targets cannot be checked against a surveyed position.',
        ], $warnings);
    }

    public function testAnEmptyMirrorIsSilentWhenNoStepNamesATarget(): void
    {
        self::assertSame([], $this->warnings([self::start(), ['name' => 'Wait', 'type' => 'wait', 'config' => ['timeout_seconds' => 30]]], []));
    }

    // ── the window ──────────────────────────────────────────────────────────────────────────

    public function testAnEmptyWindowIsNamedEmptyAndAnInvertedOneInverted(): void
    {
        $empty = $this->warnings([], [], [
            'available_from' => '2026-09-20T00:00:00+00:00',
            'available_to' => '2026-09-20T00:00:00+00:00',
        ]);
        self::assertCount(1, $empty);
        self::assertStringContainsString('The window is empty', $empty[0]);
        self::assertStringContainsString('nobody can ever run this quest', $empty[0]);

        $inverted = $this->warnings([], [], [
            'available_from' => '2026-09-20T00:00:00+00:00',
            'available_to' => '2026-09-19T00:00:00+00:00',
        ]);
        self::assertStringContainsString('The window is inverted', $inverted[0]);
    }

    public function testAWindowThatClosedInThePastSaysTheQuestIsHiddenAsSaved(): void
    {
        $warnings = $this->warnings([], [], [
            'available_from' => '2026-09-01T00:00:00+00:00',
            'available_to' => '2026-09-16T00:00:00+00:00',
        ]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('is in the past: the quest is hidden as saved', $warnings[0]);
    }

    public function testAnOpenEndedWindowIsSilent(): void
    {
        self::assertSame([], $this->warnings([], [], ['available_from' => '2026-09-01T00:00:00+00:00']));
    }

    public function testAnUnparseableDateIsIgnoredRatherThanThrowing(): void
    {
        self::assertSame([], $this->warnings([], [], ['available_from' => 'whenever', 'available_to' => 'later']));
    }

    // ── the payload ceiling ─────────────────────────────────────────────────────────────────

    public function testAPayloadOverTwentyKilobytesIsWarnedAbout(): void
    {
        $warnings = $this->warnings([], [], ['description' => str_repeat('x', QuestPreflight::PAYLOAD_LIMIT_BYTES + 1)]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('over the 20 kB preflight limit', $warnings[0]);
    }

    public function testAPayloadUnderTheCeilingIsSilent(): void
    {
        self::assertSame([], $this->warnings([], [], ['description' => str_repeat('x', 1024)]));
    }

    // ── the target key folding ──────────────────────────────────────────────────────────────

    public function testTargetKeyFolding(): void
    {
        self::assertSame('monad-fp-07', QuestPreflight::targetKey('MONAD-FP-07'));
        self::assertSame('monad-fp-07', QuestPreflight::targetKey(' MONAD-FP-07 '));
        self::assertSame('monad-fp-07', QuestPreflight::targetKey('https://monad.dubec.dev/m/MONAD-FP-07'));
        self::assertSame('monad-fp-07', QuestPreflight::targetKey('https://monad.dubec.dev/m/MONAD-FP-07/'));
        self::assertSame('monad-fp-07', QuestPreflight::targetKey('https://monad.dubec.dev/m/MONAD-FP-07?src=card#top'));
        self::assertSame('monad02', QuestPreflight::targetKey('https://monad.dubec.dev/d/monad02'));
        self::assertSame('', QuestPreflight::targetKey(''));
        // Another host is not this lab's card, so it resolves to nothing rather than to its tail.
        self::assertSame('', QuestPreflight::targetKey('https://example.test/m/MONAD-FP-07'));
    }
}
