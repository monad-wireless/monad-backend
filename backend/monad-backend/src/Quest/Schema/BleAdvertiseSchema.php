<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * Required: how long the frame must stay on air. The identity itself comes from the lab bundle's
 * advertise namespace, never from a quest config a participant can read.
 *
 * `adv_interval_ms` is a request, not a promise: Android maps it onto AdvertiseSettings buckets
 * and iOS cannot set it at all, but an impossible value is still an authoring error.
 */
final class BleAdvertiseSchema extends AbstractStepSchema
{
    public const TX_POWERS = ['ultra_low', 'low', 'medium', 'high'];
    public const INTERVAL_MIN_MS = 100;
    public const INTERVAL_MAX_MS = 10240;

    public function type(): QuestStepType
    {
        return QuestStepType::BLE_ADVERTISE;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('duration_seconds', FieldSpec::KIND_INT, true, min: 1, help: 'How long the identity frame stays on air.'),
            new FieldSpec('adv_interval_ms', FieldSpec::KIND_INT, false, min: self::INTERVAL_MIN_MS, max: self::INTERVAL_MAX_MS, help: 'Requested advertising interval; the BLE bounds are 100 to 10240 ms.'),
            new FieldSpec('tx_power', FieldSpec::KIND_ENUM, false, choices: self::TX_POWERS),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        $this->requirePositiveInteger($config, 'duration_seconds', $out);

        if (isset($config['adv_interval_ms'])) {
            $this->checkInteger($config, 'adv_interval_ms', $out);
            if (is_int($config['adv_interval_ms'])
                && ($config['adv_interval_ms'] < self::INTERVAL_MIN_MS || $config['adv_interval_ms'] > self::INTERVAL_MAX_MS)) {
                $this->invalid('adv_interval_ms', 'must be between 100 and 10240 (BLE advertising interval bounds)', $out);
            }
        }

        if (isset($config['tx_power'])) {
            $this->checkString($config, 'tx_power', $out);
            if (is_string($config['tx_power']) && !in_array($config['tx_power'], self::TX_POWERS, true)) {
                $this->invalid('tx_power', 'must be one of: ultra_low, low, medium, high', $out);
            }
        }

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Broadcast the identity frame',
            'summary' => 'Put the lab BLE identity frame on air for a fixed duration. iOS honours it only in the foreground; the quest gains the ble.advertise capability. Do not combine with a session-wide features.broadcast.',
            'disabled_reason' => null,
        ];
    }
}
