<?php

namespace App\Tests\Dto;

use App\Dto\Lab\GroundTruthBatch;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Request-body rules for the ingest endpoint.
 *
 * The rule worth defending here is partial acceptance: a device flushes a whole session in one
 * body, and a single unusable row must cost that row only.
 */
class GroundTruthBatchTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function event(string $nonce = 'n1', string $token = 'p1'): array
    {
        return [
            'lab_session_id' => 'session-1',
            'participant_token' => $token,
            'zone_id' => 'ZONE-A',
            'direction' => 'in',
            'site' => 'fiit-library',
            'mono_ns' => 1_000,
            'wall_ms' => 1_754_640_000_000,
            'scan_nonce' => $nonce,
        ];
    }

    public function testAcceptsAnEnvelopedBatch(): void
    {
        $batch = GroundTruthBatch::fromPayload(['events' => [$this->event('n1'), $this->event('n2', 'p2')]]);

        self::assertCount(2, $batch->events);
        self::assertSame([], $batch->rejected);
    }

    public function testAcceptsABareEventWithoutTheEnvelope(): void
    {
        // What a phone sends the instant it regains connectivity mid-session.
        $batch = GroundTruthBatch::fromPayload($this->event());

        self::assertCount(1, $batch->events);
        self::assertSame('n1', $batch->events[0]->scanNonce);
    }

    public function testOneBadRowDoesNotCostTheGoodOnes(): void
    {
        $batch = GroundTruthBatch::fromPayload(['events' => [
            $this->event('n1'),
            ['scan_nonce' => 'n-bad', 'direction' => 'sideways'],
            $this->event('n2', 'p2'),
        ]]);

        self::assertCount(2, $batch->events);
        self::assertCount(1, $batch->rejected);
        self::assertSame('n-bad', $batch->rejected[0]['scan_nonce']);
        self::assertSame('rejected', $batch->rejected[0]['status']);
    }

    public function testNonObjectRowIsRejectedByPosition(): void
    {
        $batch = GroundTruthBatch::fromPayload(['events' => [$this->event(), 'garbage']]);

        self::assertCount(1, $batch->events);
        self::assertCount(1, $batch->rejected);
        self::assertStringContainsString('not an object', $batch->rejected[0]['reason']);
    }

    public function testEmptyBatchIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        GroundTruthBatch::fromPayload(['events' => []]);
    }

    public function testNonArrayBodyIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        GroundTruthBatch::fromPayload('not json at all');
    }

    public function testOversizedBatchIsRefused(): void
    {
        $rows = array_map(fn (int $i): array => $this->event('n' . $i), range(1, GroundTruthBatch::MAX_BATCH + 1));

        $this->expectException(ValidationException::class);

        GroundTruthBatch::fromPayload(['events' => $rows]);
    }

    public function testABatchOfNothingButBadRowsStillParsesToZeroEvents(): void
    {
        // Not an exception: the response still has to report each refusal, so the operator learns
        // which handset is emitting rubbish instead of seeing one opaque 400.
        $batch = GroundTruthBatch::fromPayload(['events' => [
            ['scan_nonce' => 'a', 'direction' => 'toggle'],
            ['scan_nonce' => 'b'],
        ]]);

        self::assertSame([], $batch->events);
        self::assertCount(2, $batch->rejected);
    }
}
