<?php

namespace App\Tests\Entity;

use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * IP-145: who a quest is for, what order it asks for, and what a completion was worth.
 *
 * Pure entity behaviour, so no database. The three rules under test all fail in the
 * direction that looks like success, which is why each has a test rather than a comment:
 *
 *  - an unrecognised audience must show a quest, never hide one;
 *  - a malformed route policy must cost the variation, never the quest;
 *  - an award must be frozen once and never re-valued.
 */
class QuestAudienceAndRouteTest extends TestCase
{
    public function testAQuestIsPublicUntilSomebodySaysOtherwise(): void
    {
        $quest = new Quest();

        self::assertSame(Quest::AUDIENCE_PUBLIC, $quest->getAudience());
        self::assertFalse($quest->isOperatorOnly());
    }

    public function testAnUnrecognisedAudienceDegradesToPublicRatherThanThrowing(): void
    {
        // Safe in the direction that matters. An unknown value shows a quest that might
        // have been hidden, never hides one that should be shown, and the start endpoint
        // is a separate gate. A throw here would take the whole quest catalogue down
        // over one bad row.
        $quest = (new Quest())->setAudience('cohort-2027');

        self::assertSame(Quest::AUDIENCE_PUBLIC, $quest->getAudience());
        self::assertFalse($quest->isOperatorOnly());
    }

    public function testOperatorIsTheOnlyValueThatHides(): void
    {
        self::assertTrue((new Quest())->setAudience(Quest::AUDIENCE_OPERATOR)->isOperatorOnly());
        self::assertFalse((new Quest())->setAudience(null)->isOperatorOnly());
    }

    public function testAQuestWithNoPolicyServesItsDeclaredOrder(): void
    {
        // null is the answer for every quest written before IP-145, and `drawRoute()`
        // returning null is what makes the enrollment store no realised route at all.
        self::assertNull((new Quest())->drawRoute());
        self::assertNull((new Quest())->setRoutePolicy(['mode' => 'fixed'])->drawRoute());
    }

    public function testAnEmptyPolicyIsTheSameAsNoPolicy(): void
    {
        // "cleared in the admin form" and "never set" must mean one thing downstream.
        self::assertNull((new Quest())->setRoutePolicy([])->getRoutePolicy());
    }

    public function testAPoolDrawsOneOfItsRoutes(): void
    {
        $quest = (new Quest())->setRoutePolicy([
            'mode' => 'pool',
            'routes' => [
                ['MONAD-FP-15', 'monad02', 'monad08'],
                ['monad07', 'MONAD-FP-19', 'MONAD-FP-20'],
            ],
        ]);

        $drawn = $quest->drawRoute();

        self::assertIsArray($drawn);
        self::assertContains($drawn, [
            ['MONAD-FP-15', 'monad02', 'monad08'],
            ['monad07', 'MONAD-FP-19', 'MONAD-FP-20'],
        ]);
    }

    public function testTheDrawCanBeMadeDeterministicForATest(): void
    {
        $quest = (new Quest())->setRoutePolicy([
            'mode' => 'pool',
            'routes' => [['a'], ['b'], ['c'], ['d']],
        ]);

        $first = $quest->drawRoute(new Randomizer(new Mt19937(1)));
        $again = $quest->drawRoute(new Randomizer(new Mt19937(1)));

        self::assertSame($first, $again);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedPolicies(): iterable
    {
        yield 'pool with no routes key' => [['mode' => 'pool']];
        yield 'pool with an empty list' => [['mode' => 'pool', 'routes' => []]];
        yield 'pool whose routes are not lists' => [['mode' => 'pool', 'routes' => ['nope']]];
        yield 'pool of empty routes' => [['mode' => 'pool', 'routes' => [[], []]]];
        yield 'unknown mode' => [['mode' => 'shuffle', 'routes' => [['a']]]];
    }

    /**
     * @param array<string, mixed> $policy
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedPolicies')]
    public function testAMalformedPolicyCostsTheVariationNotTheQuest(array $policy): void
    {
        // Degrades to the declared order rather than throwing, on the same rule
        // `RecurrencePolicy::fromArray()` already follows: a bad policy must never take
        // the quest catalogue down.
        self::assertNull((new Quest())->setRoutePolicy($policy)->drawRoute());
    }

    public function testAnEnrollmentStartsWithNoRealisedRouteAndNoAward(): void
    {
        $enrollment = new QuestEnrollment();

        self::assertNull($enrollment->getRealisedSteps());
        self::assertNull($enrollment->getPointsAwarded());
        self::assertNull($enrollment->getAwardedAt());
    }

    public function testAnAwardIsFrozenOnceAndNeverRevalued(): void
    {
        // The whole reason the value is stored on the enrollment instead of read from
        // `quests.points` on demand. A replayed completion must not double-pay, and a
        // later re-valuation of the quest must not rewrite what a finished walk was worth.
        $enrollment = new QuestEnrollment();
        $stamped = new \DateTimeImmutable('2026-09-01 15:00:00');

        $enrollment->awardPoints(120.0, $stamped);
        $enrollment->awardPoints(999.0, new \DateTimeImmutable('2026-09-02 09:00:00'));

        self::assertSame(120.0, $enrollment->getPointsAwarded());
        self::assertEquals($stamped, $enrollment->getAwardedAt());
    }

    public function testAnEmptyRealisedRouteIsStoredAsNull(): void
    {
        // So "this quest does not vary" and "the pool produced nothing" read the same
        // downstream, which is correct: both mean the declared order was served.
        self::assertNull((new QuestEnrollment())->setRealisedSteps([])->getRealisedSteps());
    }
}
