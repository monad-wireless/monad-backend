<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/** The module id is the only contract; the rest of the config is passed opaque to the module. */
final class SensorCaptureSchema extends AbstractStepSchema
{
    public function type(): QuestStepType
    {
        return QuestStepType::SENSOR_CAPTURE;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('module', FieldSpec::KIND_STRING, true, help: 'The sensor module id (room-scan, UWB ranging). Other keys are handed to the module unchanged.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        $this->requireString($config, 'module', $out);

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Sensor capture',
            'summary' => 'Run an optional sensor module (room scan, UWB ranging). Offered only to handsets that claim the capability.',
            'disabled_reason' => null,
        ];
    }
}
