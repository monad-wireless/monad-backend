<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\QuestEnrollment;
use PHPUnit\Framework\TestCase;

/**
 * The enrollment's quarantine rules, without a database: a reason is required, a double click does
 * not move the timestamp, and release clears time and reason together (Version20261023120000).
 */
final class QuestEnrollmentQuarantineTest extends TestCase
{
    public function testQuarantineStoresTrimmedReasonAndTime(): void
    {
        $at = new \DateTimeImmutable('2026-10-23 09:00:00');
        $e = (new QuestEnrollment())->quarantine('  App Store review account  ', $at);

        self::assertTrue($e->isQuarantined());
        self::assertSame('App Store review account', $e->getQuarantineReason());
        self::assertSame($at, $e->getQuarantinedAt());
    }

    public function testBlankReasonIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new QuestEnrollment())->quarantine('   ');
    }

    public function testSecondQuarantineIsRefusedAndKeepsTheFirst(): void
    {
        $first = new \DateTimeImmutable('2026-10-23 09:00:00');
        $e = (new QuestEnrollment())->quarantine('first', $first);
        try {
            $e->quarantine('second');
            self::fail('A second quarantine must be refused.');
        } catch (\LogicException) {
        }

        self::assertSame($first, $e->getQuarantinedAt());
        self::assertSame('first', $e->getQuarantineReason());
    }

    public function testReleaseClearsBothAndRefusesWhenNotQuarantined(): void
    {
        $e = (new QuestEnrollment())->quarantine('x')->release();

        self::assertFalse($e->isQuarantined());
        self::assertNull($e->getQuarantinedAt());
        self::assertNull($e->getQuarantineReason());

        $this->expectException(\LogicException::class);
        $e->release();
    }
}
