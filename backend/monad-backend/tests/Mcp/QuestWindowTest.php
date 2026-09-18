<?php

namespace App\Tests\Mcp;

use App\Entity\Quest;
use App\Mcp\LabTools;
use PHPUnit\Framework\TestCase;

/**
 * `lab_quest_update`'s window logic, which decides whether anybody can run a quest.
 *
 * Pinned because the alternative to reading a status is comparing two nullable
 * timestamps against the clock by hand, and that is the sort of arithmetic an
 * operator gets right until the one evening they do not.
 *
 * `describeWindow` is private, so it is reached through the same reflection the tool
 * itself uses. The rule under test is the rule, not the accessor.
 */
class QuestWindowTest extends TestCase
{
    private function describe(?string $from, ?string $to): string
    {
        $quest = new Quest();
        if ($from !== null) {
            $quest->setAvailableFrom(new \DateTime($from));
        }
        if ($to !== null) {
            $quest->setAvailableTo(new \DateTime($to));
        }

        // No setAccessible(): PHP 8.1+ makes reflection on a private method callable
        // directly, and the call is deprecated as of 8.5.
        return (string) (new \ReflectionMethod(LabTools::class, 'describeWindow'))
            ->invoke(null, $quest);
    }

    public function testAnOpenEndedQuestThatHasStartedIsLive(): void
    {
        self::assertSame('live', $this->describe('-1 day', null));
    }

    public function testAQuestInsideItsWindowIsLive(): void
    {
        self::assertSame('live', $this->describe('-1 day', '+1 day'));
    }

    public function testAQuestWhoseWindowHasClosedIsHidden(): void
    {
        // What retiring produces. The quest keeps its enrolments and its history and
        // simply stops being offered.
        self::assertSame('hidden', $this->describe('-2 days', '-1 day'));
    }

    public function testAQuestThatHasNotOpenedYetIsScheduled(): void
    {
        self::assertSame('scheduled', $this->describe('+1 day', '+2 days'));
    }

    public function testNotYetOpenBeatsAlreadyClosed(): void
    {
        // A nonsensical window — opens after it closes — must not read as `live`.
        // Reporting `scheduled` is the safe answer: it says "nobody can run this",
        // which is true, rather than inviting somebody to try.
        self::assertSame('scheduled', $this->describe('+2 days', '+1 day'));
    }

    public function testAQuestWithNoDatesAtAllIsLive(): void
    {
        // `available_from` is NOT NULL in the schema, so this is unreachable through
        // the API. Pinned anyway: the method must not divide by a null and must not
        // report a quest as hidden because nobody set a date.
        self::assertSame('live', $this->describe(null, null));
    }
}
