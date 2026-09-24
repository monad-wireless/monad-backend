<?php

namespace App\Tests\Quest;

use App\Constants\ErrorCode;
use App\Exception\ValidationException;
use App\Quest\HandsetDescriptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The descriptor's three shape rules (IP-149): closed keys, verbatim storage, unknown is absent.
 */
class HandsetDescriptorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'handset_id' => '0b6f2a8e-8c1d-4e2a-9f3b-1c2d3e4f5a6b',
            'platform' => 'ios',
            'machine' => 'iPhone15,2',
            'manufacturer' => 'Apple',
            'model' => 'iPhone',
            'os_version' => '18.6',
            'os_build' => '22G86',
            'app_version' => '1.4.0',
            'build_id' => '1.4.0+41.g9a1d2f2b',
            'capabilities' => ['ble.advertise', 'camera.qr'],
            'sensors' => [['kind' => 'barometer', 'available' => true]],
            'radio' => [],
            'state' => ['thermal' => 'nominal', 'low_power_mode' => false],
        ];
    }

    public function testEmptyBodyIsNoDescriptor(): void
    {
        self::assertNull(HandsetDescriptor::fromRequestBody(''));
        self::assertNull(HandsetDescriptor::fromRequestBody("  \n"));
    }

    public function testBodyWithoutHandsetKeyIsNoDescriptor(): void
    {
        self::assertNull(HandsetDescriptor::fromRequestBody('{"something_else": 1}'));
    }

    public function testValidBodyIsStoredVerbatim(): void
    {
        $input = self::valid();
        $descriptor = HandsetDescriptor::fromRequestBody(json_encode(['handset' => $input], JSON_THROW_ON_ERROR));

        self::assertNotNull($descriptor);
        self::assertSame($input, $descriptor->toArray(), 'the snapshot is the body as received');
        self::assertSame('0b6f2a8e-8c1d-4e2a-9f3b-1c2d3e4f5a6b', $descriptor->installationId());
        self::assertSame('ios', $descriptor->platform());
    }

    public function testEmptyRadioObjectSurvivesAsEmpty(): void
    {
        // iOS publishes no BLE or Wi-Fi capability API, so the app sends `{}`. Absent is absent:
        // nothing here fills it in.
        $descriptor = HandsetDescriptor::fromArray(self::valid());
        self::assertSame([], $descriptor->toArray()['radio']);
    }

    public function testUnknownTopLevelKeyIsRejected(): void
    {
        $input = self::valid() + ['imei' => '123'];
        $this->expectException(ValidationException::class);
        try {
            HandsetDescriptor::fromArray($input);
        } catch (ValidationException $e) {
            self::assertSame(ErrorCode::VALIDATION_HANDSET_MALFORMED, $e->getErrorCode());
            throw $e;
        }
    }

    public function testOversizeBodyIsRejectedBeforeParsing(): void
    {
        $raw = '{"handset": {"handset_id": "x", "platform": "ios", "model": "' . str_repeat('a', HandsetDescriptor::MAX_BYTES) . '"}}';
        try {
            HandsetDescriptor::fromRequestBody($raw);
            self::fail('expected a validation exception');
        } catch (ValidationException $e) {
            self::assertSame(ErrorCode::VALIDATION_HANDSET_TOO_LARGE, $e->getErrorCode());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformed(): iterable
    {
        yield 'missing handset_id' => [array_diff_key(self::valid(), ['handset_id' => 1])];
        yield 'missing platform' => [array_diff_key(self::valid(), ['platform' => 1])];
        yield 'unknown platform' => [['platform' => 'harmony'] + self::valid()];
        yield 'handset_id with spaces' => [['handset_id' => 'not an id'] + self::valid()];
        yield 'capabilities not a list' => [['capabilities' => ['a' => 'b']] + self::valid()];
        yield 'capabilities with a number' => [['capabilities' => ['ble.advertise', 7]] + self::valid()];
        yield 'sensors not a list' => [['sensors' => ['kind' => 'x']] + self::valid()];
        yield 'radio is a list' => [['radio' => ['le_2m_phy']] + self::valid()];
        yield 'machine empty string' => [['machine' => ''] + self::valid()];
        yield 'model too long' => [['model' => str_repeat('m', 129)] + self::valid()];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('malformed')]
    public function testMalformedShapesAreRejected(array $input): void
    {
        $this->expectException(ValidationException::class);
        HandsetDescriptor::fromArray($input);
    }

    public function testListBodyIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        HandsetDescriptor::fromRequestBody('{"handset": ["ios"]}');
    }

    public function testNonJsonBodyIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        HandsetDescriptor::fromRequestBody('not json');
    }
}
