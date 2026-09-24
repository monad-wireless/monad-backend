<?php

namespace App\Quest;

use App\Enum\RecurrenceScope;

/**
 * How often one participant may re-run a quest (IP-128).
 *
 * A small immutable value object rather than two nullable columns, because the
 * two fields are only meaningful together: a scope without a cooldown gates
 * nothing, and a cooldown without a scope does not say what it counts. Stored as
 * JSON on `Quest.recurrence`; `null` means the pre-IP-128 behaviour.
 *
 * WHAT `null` MEANS, PRECISELY. It means *unlimited*, not once. Nothing in this
 * backend has ever prevented a replay — `Version20251202185420` dropped the
 * `user_quest_enrollment_idx` unique index, `QuestEnrollment` carries a plain
 * `#[ORM\Index]`, and `startQuest()` does no enrollment lookup at all before
 * `new QuestEnrollment()`. Any one-shot feel in the app is client-side. So this
 * class only ever *adds* a constraint, and every existing quest keeps behaving
 * exactly as it does today by having no policy at all.
 */
final class RecurrencePolicy implements \JsonSerializable
{
    private function __construct(
        public readonly RecurrenceScope $scope,
        public readonly int $cooldownSeconds,
    ) {
    }

    public static function of(RecurrenceScope $scope, int $cooldownSeconds): self
    {
        if ($cooldownSeconds < 0) {
            throw new \InvalidArgumentException('cooldown_seconds must not be negative');
        }

        return new self($scope, $cooldownSeconds);
    }

    /**
     * Rehydrate from the JSON column. Returns null for null/garbage rather than
     * throwing: a malformed policy must degrade to "no extra gate", never take
     * the quest catalogue down.
     *
     * @param array<string, mixed>|null $raw
     */
    public static function fromArray(?array $raw): ?self
    {
        if (!$raw) {
            return null;
        }

        $scope = RecurrenceScope::tryFrom((string) ($raw['scope'] ?? ''));
        if (null === $scope) {
            return null;
        }

        $cooldown = $raw['cooldown_seconds'] ?? null;
        if (!is_int($cooldown) || $cooldown < 0) {
            return null;
        }

        return new self($scope, $cooldown);
    }

    /** @return array{scope: string, cooldown_seconds: int} */
    public function jsonSerialize(): array
    {
        return [
            'scope' => $this->scope->value,
            'cooldown_seconds' => $this->cooldownSeconds,
        ];
    }

    /**
     * When a run finished at $lastCompletedAt, when may the next one start?
     */
    public function nextAvailableAt(\DateTimeImmutable $lastCompletedAt): \DateTimeImmutable
    {
        return $lastCompletedAt->modify(sprintf('+%d seconds', $this->cooldownSeconds));
    }
}
