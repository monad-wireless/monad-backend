<?php

namespace App\Tests\Dto;

use App\Dto\Quest\QuestStepDto;
use PHPUnit\Framework\TestCase;

/**
 * Who may read a step's `config`.
 *
 * `GET /api/quest/{id}` is PUBLIC_ACCESS (config/packages/security.yaml), and a `scan_qr`
 * step's config carries `expected_value` — the exact string a participant's scan is matched
 * against. Serving that to an anonymous caller hands out the answer key to the ground-truth
 * channel: the one stream that counts people rather than phones, and the only one that
 * depends on a human physically walking to a marker. It is not a secret being leaked, it is
 * a measurement being made forgeable.
 *
 * The participant flow does not regress, because the running quest never reads config from
 * this route: `POST /api/quest/{id}/start` is JWT-authenticated and its QuestStartStepDto
 * carries config, which the handset persists locally (QuestDetailScreenModel.startQuest ->
 * questStepCompletionRepository.insertStepCompletion). The public detail route only ever fed
 * the pre-start preview screen.
 */
class QuestStepDtoTest extends TestCase
{
    private function dto(bool $includeConfig): QuestStepDto
    {
        return new QuestStepDto(
            id: '0c3f6f7e-0000-4000-8000-000000000001',
            name: 'Check in',
            type: 'scan_qr',
            order: 1,
            config: $includeConfig ? ['expected_value' => 'https://monad.dubec.dev/m/MONAD-A-IN'] : null,
        );
    }

    public function testAnonymousResponseOmitsConfigEntirely(): void
    {
        $out = $this->dto(false)->toArray();

        // Omitted, not null and not []: an absent key is unambiguous, whereas an
        // empty object is a valid config a client could try to act on.
        self::assertArrayNotHasKey('config', $out);
    }

    public function testAnonymousResponseStillDescribesTheStep(): void
    {
        $out = $this->dto(false)->toArray();

        // Withholding the answer key must not withhold what the quest asks of you;
        // a stranger deciding whether to take part still needs to see the shape of it.
        self::assertSame('Check in', $out['name']);
        self::assertSame('scan_qr', $out['type']);
        self::assertSame(1, $out['order']);
    }

    public function testAuthenticatedResponseCarriesConfig(): void
    {
        $out = $this->dto(true)->toArray();

        self::assertArrayHasKey('config', $out);
        self::assertSame('https://monad.dubec.dev/m/MONAD-A-IN', $out['config']['expected_value']);
    }

    public function testConfigIsWithheldByDefault(): void
    {
        // The default is the safe one. QuestDetailResponseDto::fromEntity() shares this DTO
        // between the public detail route and the ROLE_SUPERADMIN authoring route, so a new
        // caller that forgets the flag must leak nothing rather than everything.
        $reflection = new \ReflectionMethod(QuestStepDto::class, 'fromEntity');
        $includeConfig = $reflection->getParameters()[1];

        self::assertSame('includeConfig', $includeConfig->getName());
        self::assertTrue($includeConfig->isDefaultValueAvailable());
        self::assertFalse($includeConfig->getDefaultValue());
    }
}
