<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * A scan is matched by string: the app compares the scanned text to `expected_value`
 * (case-insensitive) and consults no table, so the printed card must carry exactly that string.
 */
final class ScanQrSchema extends AbstractStepSchema
{
    public function type(): QuestStepType
    {
        return QuestStepType::SCAN_QR;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('expected_value', FieldSpec::KIND_STRING, true, help: 'The exact text the scanned code must carry. lab_marker_svg renders it.'),
            new FieldSpec('location', FieldSpec::KIND_LOCATION, true, help: 'Where the participant finds the card, in words.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        $this->requireString($config, 'expected_value', $out);
        $this->requireString($config, 'location', $out);

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Scan a code',
            'summary' => 'Scan one printed marker whose text equals expected_value. A completion, not a position: use a probe when the point is surveyed.',
            'disabled_reason' => null,
        ];
    }
}
