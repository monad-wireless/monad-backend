<?php

namespace App\Dto\Quest;

use App\Entity\QuestStep;
use App\Enum\QuestStepType;

class QuestStepDto
{
    /**
     * @param array|null $config the step's free-form settings, or null when withheld.
     *
     * Withheld is not the same as empty. `config` carries `expected_value` for a
     * `scan_qr` step — the string a participant's scan is matched against — so an
     * unauthenticated reader who receives it can satisfy a scan step without ever
     * walking to the marker. The scans are the study's people channel, the one
     * stream that counts humans rather than phones, so that is not a leak of a
     * secret but a hole in a measurement.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly int $order,
        public readonly ?array $config
    ) {
    }

    public static function fromEntity(QuestStep $step, bool $includeConfig = false): self
    {
        return new self(
            id: $step->getId()->toRfc4122(),
            name: $step->getName(),
            type: $step->getType()->value,
            order: $step->getOrder(),
            config: $includeConfig ? $step->getConfig() : null
        );
    }

    /**
     * The key is omitted entirely when withheld rather than emitted as `null` or
     * `{}`: an absent key is unambiguous, whereas an empty object is a valid
     * config that a client could try to act on.
     */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'order' => $this->order,
        ];

        if ($this->config !== null) {
            $out['config'] = $this->config;
        }

        return $out;
    }
}
