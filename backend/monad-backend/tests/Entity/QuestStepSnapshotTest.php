<?php

namespace App\Tests\Entity;

use App\Entity\QuestStep;
use App\Entity\QuestStepCompletion;
use App\Enum\QuestStepType;
use PHPUnit\Framework\TestCase;

/**
 * A finished run must describe itself after the quest has moved on (2026-09-20).
 *
 * `quest_step_completions.step_id` used to be NOT NULL, which made the step ROW the record
 * of what a participant was asked to do. The database then refused to delete those rows, so
 * `lab_quest_write` could not edit a quest anybody had run and the catalogue grew retired
 * generations instead. Worse, the row it pinned could still be EDITED, silently re-pointing
 * finished runs at text nobody walked.
 *
 * Pure entity behaviour, so no database. Each rule below fails in the direction that looks
 * like success, which is why each has its own test.
 */
class QuestStepSnapshotTest extends TestCase
{
    private function step(string $name, string $location): QuestStep
    {
        $step = new QuestStep();
        $step->setName($name);
        $step->setType(QuestStepType::WALK_TO);
        $step->setOrder(41);
        $step->setConfig(['location' => $location, 'description' => 'Look for it.']);

        return $step;
    }

    public function testASnapshotRecordsTheRealisedPositionNotTheDeclaredOne(): void
    {
        // IP-145 renumbers a pooled run, so a step declared 41st can be served 3rd. The
        // snapshot has to carry what the walker was told, which is the realised index.
        $completion = new QuestStepCompletion();
        $completion->snapshotStep($this->step('Go to Fingerprint point 12', 'FP-12'), 3);

        $snapshot = $completion->getStepSnapshot();

        self::assertSame(3, $snapshot['order']);
        self::assertSame('Go to Fingerprint point 12', $snapshot['name']);
        self::assertSame('walk_to', $snapshot['type']);
        self::assertSame('FP-12', $snapshot['config']['location']);
    }

    public function testEditingTheQuestAfterwardsCannotChangeWhatTheRunReports(): void
    {
        $step = $this->step('Go to Fingerprint point 12', 'FP-12');

        $completion = new QuestStepCompletion();
        $completion->setStep($step);
        $completion->snapshotStep($step, 3);

        // The quest is rewritten: this row now says something else entirely.
        $step->setName('Go to Node monad07');
        $step->setConfig(['location' => 'monad07', 'description' => 'Look for it.']);

        $described = $completion->describeStep();

        self::assertSame('Go to Fingerprint point 12', $described['name']);
        self::assertSame('FP-12', $described['config']['location']);
    }

    public function testDeletingTheStepCostsAJoinAndNotAFact(): void
    {
        // ON DELETE SET NULL: the pointer goes, the evidence stays.
        $step = $this->step('Go to Fingerprint point 12', 'FP-12');

        $completion = new QuestStepCompletion();
        $completion->setStep($step);
        $completion->snapshotStep($step, 3);
        $completion->setStep(null);

        self::assertNull($completion->getStep());
        self::assertSame('Go to Fingerprint point 12', $completion->describeStep()['name']);
    }

    public function testAPreSnapshotRowStillReadsThroughTheLiveStep(): void
    {
        // Every completion written before the column existed has no snapshot and must
        // stay readable. Backfilling one would invent what nobody recorded.
        $completion = new QuestStepCompletion();
        $completion->setStep($this->step('Go to Node monad01', 'monad01'));

        self::assertNull($completion->getStepSnapshot());
        self::assertSame('Go to Node monad01', $completion->describeStep()['name']);
        self::assertSame(41, $completion->describeStep()['order']);
    }

    public function testAPreSnapshotRowWhoseStepIsGoneReadsAsUnknown(): void
    {
        // The one real hole: an old completion whose quest was rewritten. It must read as
        // unknown rather than as an empty step, so nothing downstream treats it as data.
        $completion = new QuestStepCompletion();

        self::assertNull($completion->describeStep());
    }
}
