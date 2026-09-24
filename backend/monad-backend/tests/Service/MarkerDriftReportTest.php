<?php

namespace App\Tests\Service;

use App\Entity\LabPlacement;
use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use App\Service\MarkerService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The drift rule (IP-157) as a pure function: the mirror against the quest set, no database.
 *
 * Pinned because it is the same join `monad-knowledge lab quest-check` computes, and a board
 * that folded a URL differently from the handset would show a card as matched that the app
 * cannot match. Everything here is built in memory: entities, not fakes of repositories, because
 * the rule takes lists and the repositories only fetch them.
 */
class MarkerDriftReportTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-16 12:00:00+00:00');
    }

    /**
     * A quest whose window is relative to `$this->now`, never to the wall clock.
     *
     * `new \DateTime('-1 hour')` would be an hour before the day the suite happens to run, which
     * is in the future of a frozen `$now` and would make a live quest read as `scheduled` —
     * the test would pass or fail by the date.
     */
    private function quest(string $name, string $from, ?string $to): Quest
    {
        $quest = (new Quest())->setName($name)->setDescription('test');
        $quest->setAvailableFrom(\DateTime::createFromImmutable($this->now->modify($from)));
        $quest->setAvailableTo($to !== null ? \DateTime::createFromImmutable($this->now->modify($to)) : null);

        return $quest;
    }

    /** @param list<array{value: string, label?: string, kind?: string}> $targets */
    private function probe(Quest $quest, array $targets): QuestStep
    {
        $step = (new QuestStep())->setType(QuestStepType::PROBE)->setName('Find a point');
        $step->setConfig(['dwell_seconds' => 30, 'targets' => $targets]);
        $step->setQuest($quest);

        return $step;
    }

    private function scan(Quest $quest, string $expected): QuestStep
    {
        $step = (new QuestStep())->setType(QuestStepType::SCAN_QR)->setName('Check in');
        $step->setConfig(['expected_value' => $expected]);
        $step->setQuest($quest);

        return $step;
    }

    private function placement(string $key, string $kind = 'card', ?string $room = 'library-open', ?\DateTimeImmutable $syncedAt = null): LabPlacement
    {
        $layer = $kind === 'card' ? 'fiit-ground-markers' : 'fiit-ground-fleet';

        return (new LabPlacement($key, $kind, 'fiit-ground-0', $layer, 1.0, 2.0, Uuid::v4(), $syncedAt ?? $this->now))
            ->setRoom($room);
    }

    // ── the fold ─────────────────────────────────────────────────────────────────────────────

    public function testCodeKeyFoldsEveryFormOfOneCardToTheSameKey(): void
    {
        // The same table `check.py:code_key` and the handset's ProbeConfig.codeKey agree on.
        $cases = [
            'MONAD-FP-07' => 'monad-fp-07',
            'monad-fp-07' => 'monad-fp-07',
            '  MONAD-FP-07  ' => 'monad-fp-07',
            'https://monad.dubec.dev/m/MONAD-FP-07' => 'monad-fp-07',
            'https://MONAD.dubec.dev/m/MONAD-FP-07/' => 'monad-fp-07',
            'https://monad.dubec.dev/m/MONAD-FP-07?utm=x#top' => 'monad-fp-07',
            'https://monad.dubec.dev/d/monad04' => 'monad04',
            'monad04' => 'monad04',
            // A URL on another host is not a lab code at all.
            'https://example.org/m/MONAD-FP-07' => '',
            '' => '',
            '   ' => '',
        ];
        foreach ($cases as $raw => $expected) {
            self::assertSame($expected, MarkerService::codeKey((string) $raw), sprintf('codeKey(%s)', var_export($raw, true)));
        }
    }

    // ── the three verdicts ───────────────────────────────────────────────────────────────────

    public function testACardNamedByALiveQuestThroughItsUrlIsMatched(): void
    {
        $quest = $this->quest('Fingerprint', '-1 day', '+1 day');
        $steps = [$this->probe($quest, [['value' => 'https://monad.dubec.dev/m/MONAD-FP-07', 'label' => 'Point 07', 'kind' => 'card']])];

        $report = MarkerService::reconcile([$this->placement('MONAD-FP-07')], $steps, $this->now);

        self::assertCount(1, $report['matched']);
        self::assertSame('MONAD-FP-07', $report['matched'][0]['key']);
        self::assertSame(['https://monad.dubec.dev/m/MONAD-FP-07'], $report['matched'][0]['values']);
        self::assertSame('Fingerprint', $report['matched'][0]['quests'][0]['name']);
        self::assertSame('live', $report['matched'][0]['quests'][0]['status']);
        self::assertSame([], $report['spare']);
        self::assertSame([], $report['mismatch']);
        self::assertSame([], $report['historical']);
    }

    public function testANodeNamedThroughTheDeviceUrlIsMatched(): void
    {
        $quest = $this->quest('Treasure', '-1 day', null);
        $steps = [$this->probe($quest, [['value' => 'https://monad.dubec.dev/d/monad04', 'label' => 'Node monad04', 'kind' => 'node']])];

        $report = MarkerService::reconcile([$this->placement('monad04', 'node')], $steps, $this->now);

        self::assertSame('monad04', $report['matched'][0]['key']);
        self::assertSame('node', $report['matched'][0]['kind']);
        self::assertSame([], $report['mismatch']);
    }

    public function testMatchingIsCaseInsensitiveInBothDirections(): void
    {
        // The app compares case-insensitively; a bare lower-case code in a quest names the
        // upper-case card, and vice versa.
        $quest = $this->quest('Lower', '-1 day', null);
        $steps = [
            $this->scan($quest, 'monad-fp-08'),
            $this->probe($quest, [['value' => 'MONAD-FP-09', 'kind' => 'card']]),
        ];

        $report = MarkerService::reconcile(
            [$this->placement('MONAD-FP-08'), $this->placement('monad-fp-09')],
            $steps,
            $this->now,
        );

        self::assertSame(['MONAD-FP-08', 'monad-fp-09'], array_column($report['matched'], 'key'));
        self::assertSame([], $report['mismatch']);
    }

    public function testAMirroredCardNoLiveQuestNamesIsSpare(): void
    {
        $report = MarkerService::reconcile([$this->placement('MONAD-FP-12')], [], $this->now);

        self::assertSame([], $report['matched']);
        self::assertSame('MONAD-FP-12', $report['spare'][0]['key']);
        self::assertSame([], $report['mismatch']);
    }

    public function testALiveQuestNamingAnAbsentCardIsAMismatch(): void
    {
        $quest = $this->quest('Tonight', '-1 hour', '+3 hours');
        $steps = [$this->probe($quest, [['value' => 'https://monad.dubec.dev/m/MONAD-FP-99', 'kind' => 'card']])];

        $report = MarkerService::reconcile([$this->placement('MONAD-FP-07')], $steps, $this->now);

        self::assertCount(1, $report['mismatch']);
        self::assertSame('monad-fp-99', $report['mismatch'][0]['key']);
        self::assertSame(['https://monad.dubec.dev/m/MONAD-FP-99'], $report['mismatch'][0]['values']);
        self::assertSame('Tonight', $report['mismatch'][0]['quests'][0]['name']);
        // The card that IS there is spare, not matched: the quest names a different one.
        self::assertSame('MONAD-FP-07', $report['spare'][0]['key']);
    }

    public function testTwoSpellingsOfOneCardCollapseToOneMismatchWithBothValues(): void
    {
        $a = $this->quest('A', '-1 day', null);
        $b = $this->quest('B', '-1 day', null);
        $steps = [
            $this->scan($a, 'https://monad.dubec.dev/m/MONAD-SHOWCASE-IN'),
            $this->scan($b, 'MONAD-SHOWCASE-IN'),
        ];

        $report = MarkerService::reconcile([], $steps, $this->now);

        self::assertCount(1, $report['mismatch']);
        self::assertSame('monad-showcase-in', $report['mismatch'][0]['key']);
        self::assertEqualsCanonicalizing(
            ['https://monad.dubec.dev/m/MONAD-SHOWCASE-IN', 'MONAD-SHOWCASE-IN'],
            $report['mismatch'][0]['values'],
        );
        self::assertEqualsCanonicalizing(['A', 'B'], array_column($report['mismatch'][0]['quests'], 'name'));
    }

    // ── liveness ─────────────────────────────────────────────────────────────────────────────

    public function testAHiddenQuestsTargetIsHistoricalNotAMismatch(): void
    {
        // The case that motivated the side list: an old showcase quest names a bare code that
        // was never mirrored. It is retired, so it must not read as a fault tonight.
        $hidden = $this->quest('Showcase 2026-06', '-90 days', '-60 days');
        $steps = [$this->scan($hidden, 'MONAD-SHOWCASE-IN')];

        $report = MarkerService::reconcile([$this->placement('MONAD-FP-07')], $steps, $this->now);

        self::assertSame([], $report['mismatch']);
        self::assertCount(1, $report['historical']);
        self::assertSame('monad-showcase-in', $report['historical'][0]['key']);
        self::assertFalse($report['historical'][0]['in_mirror']);
        self::assertSame('hidden', $report['historical'][0]['quests'][0]['status']);
    }

    public function testAScheduledQuestsTargetIsHistoricalWithItsStatus(): void
    {
        $scheduled = $this->quest('Next week', '+7 days', '+8 days');
        $steps = [$this->probe($scheduled, [['value' => 'MONAD-FP-07', 'kind' => 'card']])];

        $report = MarkerService::reconcile([$this->placement('MONAD-FP-07')], $steps, $this->now);

        // Present in the mirror, but no LIVE quest names it: spare today.
        self::assertSame('MONAD-FP-07', $report['spare'][0]['key']);
        self::assertSame([], $report['matched']);
        self::assertTrue($report['historical'][0]['in_mirror']);
        self::assertSame('scheduled', $report['historical'][0]['quests'][0]['status']);
    }

    public function testBothAudiencesCount(): void
    {
        $operator = $this->quest('Operator take', '-1 day', null)->setAudience(Quest::AUDIENCE_OPERATOR);
        $steps = [$this->probe($operator, [['value' => 'MONAD-FP-07', 'kind' => 'card']])];

        $report = MarkerService::reconcile([$this->placement('MONAD-FP-07')], $steps, $this->now);

        self::assertSame('MONAD-FP-07', $report['matched'][0]['key']);
    }

    // ── edges ────────────────────────────────────────────────────────────────────────────────

    public function testAValueOnAForeignHostIsIgnored(): void
    {
        $quest = $this->quest('Foreign', '-1 day', null);
        $steps = [$this->scan($quest, 'https://example.org/m/MONAD-FP-07')];

        $report = MarkerService::reconcile([$this->placement('MONAD-FP-07')], $steps, $this->now);

        // Not a lab code: neither a match nor a mismatch.
        self::assertSame([], $report['mismatch']);
        self::assertSame('MONAD-FP-07', $report['spare'][0]['key']);
    }

    public function testANullRoomTravelsThroughTheVerdict(): void
    {
        $quest = $this->quest('Stairwell', '-1 day', null);
        $steps = [$this->probe($quest, [['value' => 'monad09', 'kind' => 'node']])];

        $report = MarkerService::reconcile([$this->placement('monad09', 'node', null)], $steps, $this->now);

        self::assertSame('monad09', $report['matched'][0]['key']);
        self::assertNull($report['matched'][0]['room']);
    }

    public function testSyncedAtIsTheNewestStampAndNullForAnEmptyMirror(): void
    {
        $older = new \DateTimeImmutable('2026-09-10 08:00:00+00:00');
        $newer = new \DateTimeImmutable('2026-09-15 08:00:00+00:00');

        $report = MarkerService::reconcile(
            [$this->placement('MONAD-FP-01', 'card', 'x', $older), $this->placement('MONAD-FP-02', 'card', 'x', $newer)],
            [],
            $this->now,
        );
        self::assertEquals($newer, $report['synced_at']);

        self::assertNull(MarkerService::reconcile([], [], $this->now)['synced_at']);
    }

    public function testCardPayloadIsThePortalGrammar(): void
    {
        self::assertSame('https://monad.dubec.dev/m/MONAD-FP-07', MarkerService::cardPayload('MONAD-FP-07'));
    }
}
