<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * The start step carries the SESSION-scoped `features` block: broadcast, track, witness,
 * illuminator. Every flag defaults to false on the phone, so a quest that wants the identity
 * frame on air across the whole run must say `features.broadcast = true` here; a probe quest
 * without it records dwells with nothing transmitting. The block was never validated before
 * IP-157; the four flags are now type-checked when present, and unknown keys pass as before.
 */
final class StartSchema extends AbstractStepSchema
{
    public const FEATURES = ['broadcast', 'track', 'witness', 'illuminator'];

    public function type(): QuestStepType
    {
        return QuestStepType::START;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('features.broadcast', FieldSpec::KIND_BOOL, false, help: 'Broadcast the lab identity frame for the whole session so the fleet can hear the phone between steps.'),
            new FieldSpec('features.track', FieldSpec::KIND_BOOL, false, help: 'Record the trajectory (pose stream) for the whole session.'),
            new FieldSpec('features.witness', FieldSpec::KIND_BOOL, false, help: 'Monitor the beacon zones and record zone transitions.'),
            new FieldSpec('features.illuminator', FieldSpec::KIND_BOOL, false, help: 'Generate traffic for the CSI receivers. Inert while the bundle has no access point.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);

        if (array_key_exists('features', $config)) {
            if (!is_array($config['features'])) {
                $this->wrongType('features', 'an object', $config['features'], $out);

                return $out;
            }
            foreach (self::FEATURES as $flag) {
                if (isset($config['features'][$flag]) && !is_bool($config['features'][$flag])) {
                    $this->wrongType('features.' . $flag, 'a boolean', $config['features'][$flag], $out);
                }
            }
        }

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Start',
            'summary' => 'The briefing the participant reads before the run, and the session features (broadcast, track, witness, illuminator) that stay on for the whole run.',
            'disabled_reason' => null,
        ];
    }
}
