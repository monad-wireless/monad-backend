<?php

namespace App\Tests\Dto;

use App\Dto\Lab\GroundTruthScanDto;
use App\Enum\GroundTruthDirection;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract.
 *
 * These nine field names are frozen by the pre-registration and shared with the `ground_truth.tsv`
 * artefact, so a rename is a silent corpus split rather than a compile error. Pinning them in a
 * test is what makes that rename loud.
 */
class GroundTruthScanDtoTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'lab_session_id' => 'session-1',
            'participant_token' => 'p-7f3a9c',
            'zone_id' => 'ZONE-A',
            'direction' => 'in',
            'site' => 'fiit-library',
            'mono_ns' => 843_221_004_991_233,
            'wall_ms' => 1_754_640_000_000,
            'scan_nonce' => 'nonce-1',
            'recording_session_id' => 'rec-1',
        ], $overrides);
    }

    public function testParsesTheRegisteredSchema(): void
    {
        $dto = GroundTruthScanDto::fromArray($this->payload());

        self::assertSame('session-1', $dto->labSessionId);
        self::assertSame('p-7f3a9c', $dto->participantToken);
        self::assertSame('ZONE-A', $dto->zoneId);
        self::assertSame(GroundTruthDirection::IN, $dto->direction);
        self::assertSame('fiit-library', $dto->site);
        self::assertSame(843_221_004_991_233, $dto->monoNs);
        self::assertSame(1_754_640_000_000, $dto->wallMs);
        self::assertSame('nonce-1', $dto->scanNonce);
        self::assertSame('rec-1', $dto->recordingSessionId);
    }

    public function testNanosecondsMayArriveAsAStringWithoutPrecisionLoss(): void
    {
        // Monotonic nanoseconds exceed 2^53 on a device that has been up for a few months, and
        // some JSON stacks round that silently. A numeric string is a legitimate encoding.
        $dto = GroundTruthScanDto::fromArray($this->payload([
            'mono_ns' => '9007199254740993',
        ]));

        self::assertSame(9007199254740993, $dto->monoNs);
    }

    public function testSiteAndRecordingSessionAreOptional(): void
    {
        // A scan is valid whether or not the phone was instrumenting at the time — a participant
        // who arrives before the operator starts the run is still a person in the room.
        $payload = $this->payload();
        unset($payload['site'], $payload['recording_session_id']);

        $dto = GroundTruthScanDto::fromArray($payload);

        self::assertSame('', $dto->site);
        self::assertNull($dto->recordingSessionId);
    }

    public function testUnresolvedToggleIsRefused(): void
    {
        // Toggle resolution happens on the handset against that participant's own history. A row
        // still saying "toggle" means it never ran, and the server has no state to guess with.
        $this->expectException(\InvalidArgumentException::class);

        GroundTruthScanDto::fromArray($this->payload(['direction' => 'toggle']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFieldProvider(): iterable
    {
        yield 'lab session' => ['lab_session_id'];
        yield 'participant token' => ['participant_token'];
        yield 'zone' => ['zone_id'];
        yield 'nonce' => ['scan_nonce'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredFieldProvider')]
    public function testMissingRequiredFieldIsRefused(string $field): void
    {
        $payload = $this->payload();
        unset($payload[$field]);

        $this->expectException(\InvalidArgumentException::class);

        GroundTruthScanDto::fromArray($payload);
    }

    public function testNonNumericTimestampIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GroundTruthScanDto::fromArray($this->payload(['mono_ns' => 'not-a-number']));
    }

    public function testOverlongFieldIsRefusedRatherThanTruncated(): void
    {
        // Truncation would silently merge two participants into one token.
        $this->expectException(\InvalidArgumentException::class);

        GroundTruthScanDto::fromArray($this->payload([
            'participant_token' => str_repeat('x', 129),
        ]));
    }

    public function testIdentityTripleIsTheContradictionKey(): void
    {
        $dto = GroundTruthScanDto::fromArray($this->payload());

        self::assertSame('p-7f3a9c|ZONE-A|in', $dto->identityTriple());
    }
}
