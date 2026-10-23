<?php

namespace App\Tests\Dto;

use App\Dto\Lab\MarkerIndexResponseDto;
use PHPUnit\Framework\TestCase;

/**
 * What `GET /api/lab/marker-index` may say to an anonymous caller (IP-129 §2.4).
 *
 * The route is PUBLIC_ACCESS because 28 printed cards send a stranger's camera to
 * `https://monad.dubec.dev/m/<code>`, and that page cannot tell them whether their card is in use
 * without asking. Public means this projection is the whole of the guard: nothing filters it
 * downstream, so a field that reaches the array reaches the internet.
 *
 * Two must never arrive. A step's `config`, because `expected_value` is the answer key to the
 * ground-truth channel (QuestStepDtoTest). And any placement — `QrCode.position` is where a card
 * physically is, which the card itself deliberately does not say.
 */
class MarkerIndexResponseDtoTest extends TestCase
{
    /** @return list<array<string, mixed>> as MarkerService::markers() returns it */
    private function projection(): array
    {
        return [[
            'value' => 'https://monad.dubec.dev/m/MONAD-A-IN',
            'label' => 'Check in',
            'quests' => [[
                'id' => '0c3f6f7e-0000-4000-8000-000000000001',
                'name' => 'EXP-C1 Day 1',
                'description' => 'A four-minute walk past the study desks.',
                'points' => 12.5,
                'estimated_duration' => 4,
            ]],
        ]];
    }

    public function testMarkerCarriesItsValueAndLabel(): void
    {
        $out = (new MarkerIndexResponseDto($this->projection()))->toArray();

        self::assertCount(1, $out['markers']);
        self::assertSame('https://monad.dubec.dev/m/MONAD-A-IN', $out['markers'][0]['value']);
        self::assertSame('Check in', $out['markers'][0]['label']);
    }

    public function testQuestsCarryIdsAlongsideNames(): void
    {
        // The whole reason the projection was widened: the portal links a marker to
        // `/quests/<id>`, and a name is not a key — two quests may share one.
        $quest = (new MarkerIndexResponseDto($this->projection()))->toArray()['markers'][0]['quests'][0];

        self::assertSame('0c3f6f7e-0000-4000-8000-000000000001', $quest['id']);
        self::assertSame('EXP-C1 Day 1', $quest['name']);
        self::assertSame('A four-minute walk past the study desks.', $quest['description']);
        self::assertSame(12.5, $quest['points']);
        self::assertSame(4, $quest['estimated_duration']);
    }

    public function testUnknownFieldsAreDroppedRatherThanPassedThrough(): void
    {
        // The projection is an allowlist, so a column added to `QuestStep` or a key added to
        // MarkerService later cannot publish itself by riding along.
        $projection = $this->projection();
        $projection[0]['position'] = 'Doorframe, north side';
        $projection[0]['quests'][0]['config'] = ['expected_value' => 'MONAD-A-IN'];

        $out = (new MarkerIndexResponseDto($projection))->toArray();

        self::assertArrayNotHasKey('position', $out['markers'][0]);
        self::assertArrayNotHasKey('config', $out['markers'][0]['quests'][0]);
        self::assertStringNotContainsString('Doorframe', json_encode($out, JSON_THROW_ON_ERROR));
    }

    public function testAMarkerNoQuestUsesSerialisesAsAnEmptyList(): void
    {
        // `[]` must survive as a JSON array, not become `{}`: the portal reads the absence of
        // quests as "printed but not in use", which is the state 20 of the 28 cards are in.
        $out = (new MarkerIndexResponseDto([[
            'value' => 'MONAD-FP-07',
            'label' => 'MONAD-FP-07',
            'quests' => [],
        ]]))->toArray();

        self::assertSame([], $out['markers'][0]['quests']);
        self::assertStringContainsString('"quests":[]', json_encode($out, JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyProjectionIsAnEmptyList(): void
    {
        // No quest asks for a scan yet. An empty index is a real answer and must serialise as
        // one — the portal distinguishes it from "could not ask", and gets that distinction
        // wrong if this ever becomes `{"markers":{}}`.
        $out = (new MarkerIndexResponseDto([]))->toArray();

        self::assertSame([], $out['markers']);
        self::assertStringContainsString('"markers":[]', json_encode($out, JSON_THROW_ON_ERROR));
    }
}
