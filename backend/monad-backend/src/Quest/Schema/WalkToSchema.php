<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * Either a named `location` or a `latitude`/`longitude` pair; an optional positive `radius`.
 */
final class WalkToSchema extends AbstractStepSchema
{
    public function type(): QuestStepType
    {
        return QuestStepType::WALK_TO;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('location', FieldSpec::KIND_LOCATION, false, help: 'A named place. Required unless latitude and longitude are given.'),
            new FieldSpec('latitude', FieldSpec::KIND_NUMBER, false, min: -90, max: 90, help: 'Required together with longitude when no location is named.'),
            new FieldSpec('longitude', FieldSpec::KIND_NUMBER, false, min: -180, max: 180),
            new FieldSpec('radius', FieldSpec::KIND_NUMBER, false, min: 0, help: 'Arrival radius in metres; must be positive when given.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);

        $hasLocation = isset($config['location']) && is_string($config['location']) && !empty($config['location']);
        $hasCoordinates = isset($config['latitude']) && isset($config['longitude']);

        if (!$hasLocation && !$hasCoordinates) {
            $this->invalid('location/coordinates', 'either "location" or "latitude" and "longitude" must be provided', $out);
        }

        if ($hasCoordinates) {
            $this->checkNumber($config, 'latitude', $out);
            $this->checkNumber($config, 'longitude', $out);

            if (isset($config['latitude']) && is_numeric($config['latitude'])) {
                $lat = (float) $config['latitude'];
                if ($lat < -90 || $lat > 90) {
                    $this->invalid('latitude', 'must be between -90 and 90', $out);
                }
            }

            if (isset($config['longitude']) && is_numeric($config['longitude'])) {
                $lng = (float) $config['longitude'];
                if ($lng < -180 || $lng > 180) {
                    $this->invalid('longitude', 'must be between -180 and 180', $out);
                }
            }
        }

        if (isset($config['radius'])) {
            $this->checkPositiveNumber($config, 'radius', $out);
        }

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Walk to a place',
            'summary' => 'Ask the participant to go to a named place (or a coordinate). Completed by the participant, not by a scan.',
            'disabled_reason' => null,
        ];
    }
}
