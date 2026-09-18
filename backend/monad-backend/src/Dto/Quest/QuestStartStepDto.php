<?php

namespace App\Dto\Quest;

use App\Entity\QuestStep;
use App\Entity\QuestStepCompletion;
use Symfony\Component\Uid\Uuid;

class QuestStartStepDto
{
    public function __construct(
        public readonly Uuid $stepId,
        public readonly Uuid $stepCompletionId,
        public readonly string $name,
        public readonly string $type,
        public readonly int $order,
        public readonly array $config
    ) {
    }

    /**
     * @param int|null $order Overrides the step's own order (IP-145).
     *
     * A pooled quest declares every legal target and asks each walker for a subset of them,
     * in an order chosen at enrollment. The step ROWS are shared by every enrollment and must
     * not be renumbered, so the realised sequence is expressed here, per response.
     *
     * Renumbered rather than merely re-listed because the client's ordering is not something
     * this DTO can assume: a handset that sorts by `order` would otherwise walk the declared
     * sequence while the server believed it had asked for another, and the symptom is a route
     * that looks fine and pairs with nothing.
     */
    public static function fromEntities(
        QuestStep $step,
        QuestStepCompletion $completion,
        ?int $order = null,
    ): self {
        return new self(
            stepId: $step->getId(),
            stepCompletionId: $completion->getId(),
            name: $step->getName(),
            type: $step->getType()->value,
            order: $order ?? $step->getOrder(),
            config: $step->getConfig()
        );
    }

    public function toArray(): array
    {
        return [
            'step_id' => (string) $this->stepId,
            'step_completion_id' => (string) $this->stepCompletionId,
            'name' => $this->name,
            'type' => $this->type,
            'order' => $this->order,
            'config' => $this->config
        ];
    }
}
