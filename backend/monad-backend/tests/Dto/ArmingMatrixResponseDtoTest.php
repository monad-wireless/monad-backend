<?php

namespace App\Tests\Dto;

use App\Dto\Lab\ArmingMatrixResponseDto;
use PHPUnit\Framework\TestCase;

/**
 * What `GET /api/lab/arming-matrix` may say to an anonymous caller (IP-129 §4.2).
 *
 * The route is PUBLIC_ACCESS because the portal's read-only ops view at `/quests/config` reads it
 * over loopback, and public means this projection is the whole of the guard: nothing filters it
 * downstream, so a field that reaches the array reaches the internet.
 *
 * Two properties are load-bearing rather than cosmetic. Every quest x device cell must carry a
 * reason — the matrix exists precisely because `QuestAvailabilityFilter::isHidden()` turns
 * `window_closed`, `not_armed` and `device_inactive` into an absence a reader cannot tell apart
 * from "armed and fine". And nothing that identifies a step, a card's placement or a node's radio
 * may appear: the projection carries no steps at all, which is the strongest form of the
 * `expected_value` guarantee QuestStepDtoTest states in the negative.
 */
class ArmingMatrixResponseDtoTest extends TestCase
{
    /** @return list<array<string, mixed>> as ArmingMatrixController assembles it */
    private function projection(): array
    {
        return [[
            'id' => '0c3f6f7e-0000-4000-8000-000000000001',
            'name' => 'EXP-C1 Day 1',
            'description' => 'A four-minute walk past the study desks.',
            'points' => 12.5,
            'estimated_duration' => 4,
            'available_from' => '2026-08-14T08:00:00+00:00',
            'available_to' => null,
            'recurrence' => ['scope' => 'per_device', 'cooldown_seconds' => 3600],
            'required_capabilities' => ['ble', 'wifi'],
            'devices' => [
                [
                    'slug' => 'monad02',
                    'armed' => true,
                    'availability' => ['available' => true, 'reason' => null, 'retry_at' => null],
                ],
                [
                    'slug' => 'monad03',
                    'armed' => false,
                    'availability' => ['available' => false, 'reason' => 'not_armed', 'retry_at' => null],
                ],
            ],
        ]];
    }

    public function testQuestCarriesItsWindowRecurrenceAndCapabilities(): void
    {
        $quest = (new ArmingMatrixResponseDto($this->projection()))->toArray()['quests'][0];

        self::assertSame('0c3f6f7e-0000-4000-8000-000000000001', $quest['id']);
        self::assertSame('EXP-C1 Day 1', $quest['name']);
        self::assertSame(12.5, $quest['points']);
        self::assertSame(4, $quest['estimated_duration']);
        self::assertSame('2026-08-14T08:00:00+00:00', $quest['available_from']);
        self::assertSame(['scope' => 'per_device', 'cooldown_seconds' => 3600], $quest['recurrence']);
        self::assertSame(['ble', 'wifi'], $quest['required_capabilities']);
    }

    public function testAnOpenEndedWindowKeepsBothKeys(): void
    {
        // `null` rather than an omitted key: a client that has not learned the difference
        // between "unset" and "unbounded" reads a missing `available_to` as "no window", which
        // is the opposite claim for a quest that has a start and runs forever.
        $quest = (new ArmingMatrixResponseDto($this->projection()))->toArray()['quests'][0];

        self::assertArrayHasKey('available_to', $quest);
        self::assertNull($quest['available_to']);
    }

    public function testEveryCellCarriesAnArmedFlagAndAReason(): void
    {
        // The entire reason this endpoint exists. `isHidden()` drops `not_armed` from the
        // participant's list, so the second cell would be an absence there — and an absence in
        // an ops matrix is indistinguishable from "outside its window" or "node out of service".
        $devices = (new ArmingMatrixResponseDto($this->projection()))->toArray()['quests'][0]['devices'];

        self::assertTrue($devices[0]['armed']);
        self::assertTrue($devices[0]['availability']['available']);
        self::assertNull($devices[0]['availability']['reason']);

        self::assertFalse($devices[1]['armed']);
        self::assertFalse($devices[1]['availability']['available']);
        self::assertSame('not_armed', $devices[1]['availability']['reason']);
    }

    public function testArmedAndAvailableStayIndependent(): void
    {
        // A quest armed at a node can still be blocked by its window. Deriving one from the
        // other would collapse the two facts the ops view is read to separate.
        $projection = $this->projection();
        $projection[0]['devices'][0]['availability'] = [
            'available' => false,
            'reason' => 'window_closed',
            'retry_at' => null,
        ];

        $cell = (new ArmingMatrixResponseDto($projection))->toArray()['quests'][0]['devices'][0];

        self::assertTrue($cell['armed']);
        self::assertFalse($cell['availability']['available']);
        self::assertSame('window_closed', $cell['availability']['reason']);
    }

    public function testUnknownFieldsAreDroppedRatherThanPassedThrough(): void
    {
        // The projection is an allowlist, so a column added to Quest, QuestStep or Device later
        // cannot publish itself by riding along. Steps and MACs are the two that would hurt.
        $projection = $this->projection();
        $projection[0]['steps'] = [['type' => 'scan_qr', 'config' => ['expected_value' => 'MONAD-A-IN']]];
        $projection[0]['devices'][0]['radio_mac'] = 'AA:BB:CC:DD:EE:FF';
        $projection[0]['devices'][0]['position'] = 'Doorframe, north side';

        $out = (new ArmingMatrixResponseDto($projection))->toArray();
        $encoded = json_encode($out, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('steps', $out['quests'][0]);
        self::assertArrayNotHasKey('radio_mac', $out['quests'][0]['devices'][0]);
        self::assertArrayNotHasKey('position', $out['quests'][0]['devices'][0]);
        self::assertStringNotContainsString('expected_value', $encoded);
        self::assertStringNotContainsString('AA:BB:CC:DD:EE:FF', $encoded);
    }

    public function testAQuestArmedNowhereSerialisesAsAnEmptyList(): void
    {
        // `[]` must survive as a JSON array, not become `{}`: the portal reads an empty device
        // list as "this quest reaches no node", which is a real and reportable state.
        $projection = $this->projection();
        $projection[0]['devices'] = [];

        $out = (new ArmingMatrixResponseDto($projection))->toArray();

        self::assertSame([], $out['quests'][0]['devices']);
        self::assertStringContainsString('"devices":[]', json_encode($out, JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyMatrixIsAnEmptyList(): void
    {
        // No quest is authored yet. An empty matrix is a real answer and must serialise as one —
        // the portal distinguishes it from "could not ask", and gets that wrong if this ever
        // becomes `{"quests":{}}`.
        $out = (new ArmingMatrixResponseDto([]))->toArray();

        self::assertSame([], $out['quests']);
        self::assertStringContainsString('"quests":[]', json_encode($out, JSON_THROW_ON_ERROR));
    }
}
