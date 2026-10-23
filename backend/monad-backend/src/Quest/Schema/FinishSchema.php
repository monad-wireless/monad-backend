<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

final class FinishSchema extends AbstractStepSchema
{
    public function type(): QuestStepType
    {
        return QuestStepType::FINISH;
    }

    public function fields(): array
    {
        return [$this->descriptionField()];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Finish',
            'summary' => 'The closing sentence the participant reads. Ends the session and starts the upload.',
            'disabled_reason' => null,
        ];
    }
}
