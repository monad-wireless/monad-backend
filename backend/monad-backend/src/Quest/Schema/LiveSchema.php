<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;
use App\Quest\Live\LiveEngineSpecs;

/**
 * A step whose screen the server drives: `{engine, options}`.
 *
 * The step type is one for every mechanic on purpose. A new engine validates its own options here
 * and needs no new step type, no new app step and no migration.
 */
final class LiveSchema extends AbstractStepSchema
{
    public function type(): QuestStepType
    {
        return QuestStepType::LIVE;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('engine', FieldSpec::KIND_ENUM, true, choices: array_keys(LiveEngineSpecs::all()), help: 'Which server engine runs the step. Its options are authored as JSON under Advanced.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        if (!$this->requireString($config, 'engine', $out)) {
            return $out;
        }
        $spec = LiveEngineSpecs::find($config['engine']);
        if ($spec === null) {
            $this->invalid('engine', 'must be one of: ' . implode(', ', array_keys(LiveEngineSpecs::all())), $out);

            return $out;
        }
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            $this->wrongType('options', 'an object', $options, $out);

            return $out;
        }
        foreach ($spec->problems($options) as $problem) {
            $this->invalid('options', $problem, $out);
        }

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Live step',
            'summary' => 'The server drives the screen: ' . implode(' ', array_map(
                static fn ($spec): string => $spec->key() . ' (' . $spec->summary() . ')',
                LiveEngineSpecs::all(),
            )),
            'disabled_reason' => null,
        ];
    }
}
