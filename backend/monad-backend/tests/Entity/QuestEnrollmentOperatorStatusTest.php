<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Quest;
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

    public function testHandCompletedRunEarnsTheQuestPoints(): void
    {
        // A failed run that an operator accepts used to keep a NULL award and count 0 points.
        $run = (new QuestEnrollment())->setQuest((new Quest())->setPoints(120.0));
        $run->markCompletionReceived();
        $run->changeStatusByOperator(QuestEnrollmentStatus::FAILED);
        self::assertNull($run->getPointsAwarded());

        $run->changeStatusByOperator(QuestEnrollmentStatus::COMPLETED);
        self::assertSame(120.0, $run->getPointsAwarded());
        self::assertNotNull($run->getAwardedAt());
    }

    public function testAnExistingAwardIsKept(): void
    {
        $quest = (new Quest())->setPoints(60.0);
        $run = (new QuestEnrollment())->setQuest($quest);
        $run->markCompletionReceived();
        $run->awardPoints(60.0);
        $run->changeStatusByOperator(QuestEnrollmentStatus::ABANDONED);
        $quest->setPoints(500.0);
        $run->changeStatusByOperator(QuestEnrollmentStatus::COMPLETED);
        self::assertSame(60.0, $run->getPointsAwarded());
    }

    public function testSameStateIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new QuestEnrollment())->changeStatusByOperator(QuestEnrollmentStatus::IN_PROGRESS);
    }
}
