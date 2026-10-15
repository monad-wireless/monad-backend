<?php

namespace App\Quest;

use App\Entity\Quest;
use App\Entity\QuestRevision;
use App\Entity\QuestStep;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one way a quest's steps change (2026-10-15).
 *
 * A quest is ONE row per name and is edited in place, so the app always lists the latest and
 * only version. Before this class the admin locked a quest's steps after its first completion
 * and offered "Duplicate as revision", which created a second row named "<name> (YYYY-MM)"; the
 * catalogue still carries those generations. The lock existed for a foreign key that
 * Version20260920180000 removed, since when every completion freezes its own step_snapshot.
 *
 * Every caller (`lab_quest_write`, the admin form, the admin import) hands this the validated
 * replacement steps. It compares them with the stored ones and, when they differ, replaces the
 * rows, bumps {@see Quest::getRevision()} and appends a {@see QuestRevision}. An identical write
 * changes nothing and publishes no revision, so a re-run of the same generator is a no-op.
 *
 * The caller sets the quest's required capabilities BEFORE calling, because the revision
 * records them, and flushes afterwards.
 */
final class QuestRevisionWriter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<QuestStep> $steps built and validated, not yet attached to any quest
     *
     * @return array{revision: int, changed: bool}
     */
    public function publish(Quest $quest, array $steps, ?User $author, string $source): array
    {
        $proposed = self::specOf($steps);
        if ($quest->getRevision() > 0 && self::canonical($proposed) === self::canonical($quest->stepSpec())) {
            return ['revision' => $quest->getRevision(), 'changed' => false];
        }

        $old = $quest->getSteps()->toArray();
        if ($old !== []) {
            foreach ($old as $step) {
                $quest->removeStep($step);
            }
            // The DELETEs must reach the database before the INSERTs: (quest_id, order) is
            // unique and Doctrine emits INSERTs first within one flush.
            $this->entityManager->flush();
        }
        foreach ($steps as $step) {
            $quest->addStep($step);
            $this->entityManager->persist($step);
        }

        $revision = $quest->getRevision() + 1;
        $quest->setRevision($revision);
        $this->entityManager->persist($quest);
        $this->entityManager->persist(new QuestRevision(
            $quest,
            $revision,
            $proposed,
            array_values($quest->getRequiredCapabilities()),
            $source,
            $author,
        ));

        return ['revision' => $revision, 'changed' => true];
    }

    /**
     * A step set as one comparable string: object keys sorted (key order is not content),
     * values compared exactly. Loose `==` would equate null with 0 and "5" with 5, and a
     * config edit that changes only a type would publish no revision.
     *
     * @param list<array<string, mixed>> $spec
     */
    private static function canonical(array $spec): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($sort, $value);
        };

        return (string) json_encode($sort($spec), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param list<QuestStep> $steps
     *
     * @return list<array<string, mixed>>
     */
    private static function specOf(array $steps): array
    {
        usort($steps, static fn (QuestStep $a, QuestStep $b): int => (int) $a->getOrder() <=> (int) $b->getOrder());

        return array_values(array_map(static fn (QuestStep $s): array => [
            'order' => (int) $s->getOrder(),
            'name' => $s->getName(),
            'type' => $s->getType()?->value,
            'config' => $s->getConfig(),
        ], $steps));
    }
}
