<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * IP-140: the human headcount step.
 *
 * `min_readings` is required and has no default on purpose. A count step that silently accepts
 * one reading and completes is the difference between a measurement and an anecdote, and the
 * number of readings is a design decision the quest author has to make out loud.
 *
 * `max_count` is an upper bound on the counter, so a fat finger cannot enter 400 people into a
 * room with 83 seats. Optional: a room whose capacity nobody has stated should not get a
 * fabricated one here.
 */
final class ObserveSchema extends AbstractStepSchema
{
    public function type(): QuestStepType
    {
        return QuestStepType::OBSERVE;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('prompt', FieldSpec::KIND_STRING, true, help: 'The question the participant answers with a number.'),
            new FieldSpec('min_readings', FieldSpec::KIND_INT, true, min: 1, help: 'How many readings complete the step. No default: the author decides.'),
            new FieldSpec('max_count', FieldSpec::KIND_INT, false, min: 1, help: 'Optional ceiling on the counter, e.g. the room capacity.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        $this->requireString($config, 'prompt', $out);
        $this->requirePositiveInteger($config, 'min_readings', $out);

        if (isset($config['max_count'])) {
            $this->checkPositiveInteger($config, 'max_count', $out);
        }

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Observe (count people)',
            'summary' => 'Ask the participant for a number they can see, several times over. The only channel that counts people rather than phones; never reconciled against the BLE count.',
            'disabled_reason' => null,
        ];
    }
}
