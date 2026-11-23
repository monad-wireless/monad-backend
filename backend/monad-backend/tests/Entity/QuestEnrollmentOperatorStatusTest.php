<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\QuestEnrollment;
use App\Enum\QuestEnrollmentStatus;
use PHPUnit\Framework\TestCase;

final class QuestEnrollmentOperatorStatusTest extends TestCase
{
    public function testOpenRunCanBeAbandonedAndReopened(): void
    {
        $run = new QuestEnrollment();
        $run->changeStatusByOperator(QuestEnrollmentStatus::ABANDONED);
        self::assertSame(QuestEnrollmentStatus::ABANDONED, $run->getStatus());
        $run->changeStatusByOperator(QuestEnrollmentStatus::IN_PROGRESS);
        self::assertSame(QuestEnrollmentStatus::IN_PROGRESS, $run->getStatus());
        self::assertNull($run->getCompletionReceivedAt());
        self::assertNull($run->getPointsAwarded());
    }

    public function testCompletedNeedsAReceivedCompletion(): void
    {
        $run = new QuestEnrollment();
        try {
            $run->changeStatusByOperator(QuestEnrollmentStatus::COMPLETED);
            self::fail('A run the server never saw complete was marked completed.');
        } catch (\LogicException) {
            self::assertSame(QuestEnrollmentStatus::IN_PROGRESS, $run->getStatus());
        }
        $run->markCompletionReceived();
        $run->changeStatusByOperator(QuestEnrollmentStatus::FAILED);
        $run->changeStatusByOperator(QuestEnrollmentStatus::COMPLETED);
        self::assertSame(QuestEnrollmentStatus::COMPLETED, $run->getStatus());
    }

    public function testSameStateIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new QuestEnrollment())->changeStatusByOperator(QuestEnrollmentStatus::IN_PROGRESS);
    }
}
