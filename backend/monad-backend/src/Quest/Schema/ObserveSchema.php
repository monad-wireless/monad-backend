<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;
use App\Lab\Contract\CountingContracts;

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
            // The typed form authors the legacy partial view, where this is required; a room sweep
            // is authored as raw JSON under Advanced (or by `lab quest-build`), which skips the form.
            new FieldSpec('min_readings', FieldSpec::KIND_INT, true, min: 1, help: 'How many readings complete the step. No default: the author decides. Not read by a room sweep (schema set).'),
            new FieldSpec('max_count', FieldSpec::KIND_INT, false, min: 1, help: 'Optional ceiling on the counter, e.g. the room capacity.'),
            new FieldSpec(
                'schema',
                FieldSpec::KIND_STRING,
                false,
                help: 'IP-162: set to monad-quest/observe/v2 for a room sweep. Then mode, protocol_id, protocol_sha256, '
                    . 'rooms, checkpoints and observer_convention are required (author them under Advanced; '
                    . '`monad-knowledge lab quest-build --template counting` prints a valid block). Leave empty for the legacy partial view.',
            ),
        ];
    }

    /**
     * Two contracts, dispatched on `schema` (IP-162). Absent: the legacy partial view — prompt
     * plus min_readings, the rules this step has always had. `monad-quest/observe/v2`: the room
     * sweep, validated by {@see CountingContracts::observeConfigProblems()} including the
     * self-digest. Any other schema is refused outright: a client that cannot tell which of two
     * measurements a step asks for must not be allowed to guess.
     */
    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);

        if (array_key_exists('schema', $config) && $config['schema'] !== null) {
            if ($config['schema'] !== CountingContracts::SCHEMA_OBSERVE_V2) {
                $this->invalid('schema', sprintf('unknown observe schema; only %s or no schema (legacy) is accepted', CountingContracts::SCHEMA_OBSERVE_V2), $out);

                return $out;
            }
            foreach (CountingContracts::observeConfigProblems($config) as $problem) {
                $out[] = $this->message(Messages::INVALID_VALUE, ['field' => 'config', 'reason' => $problem]);
            }

            return $out;
        }

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
