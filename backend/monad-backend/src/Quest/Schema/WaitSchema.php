<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

final class WaitSchema extends AbstractStepSchema
{
    public function type(): QuestStepType
    {
        return QuestStepType::WAIT;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('timeout_seconds', FieldSpec::KIND_INT, true, min: 1, help: 'How long the participant holds still before the step completes.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        $this->requirePositiveInteger($config, 'timeout_seconds', $out);

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Wait',
            'summary' => 'Hold still for a fixed number of seconds. A timed pause on the session timeline.',
            'disabled_reason' => null,
        ];
    }
}
