<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

final class FindBleDeviceSchema extends AbstractStepSchema
{
    private const MAC_PATTERN = '/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/';

    public function type(): QuestStepType
    {
        return QuestStepType::FIND_BLE_DEVICE;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('device_name', FieldSpec::KIND_STRING, true, help: 'The advertised name to look for.'),
            new FieldSpec('device_id', FieldSpec::KIND_STRING, false, help: 'Optional MAC address, XX:XX:XX:XX:XX:XX.'),
            new FieldSpec('rssi_threshold', FieldSpec::KIND_INT, false, help: 'Optional dBm floor; an integer, usually negative.'),
            new FieldSpec('detection_duration', FieldSpec::KIND_INT, false, min: 1, help: 'Optional seconds the device must stay in range.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        $this->requireString($config, 'device_name', $out);

        if (isset($config['device_id']) && is_string($config['device_id'])) {
            if (!preg_match(self::MAC_PATTERN, $config['device_id'])) {
                $out[] = $this->message(Messages::INVALID_MAC_ADDRESS, ['field' => 'device_id']);
            }
        }

        if (isset($config['rssi_threshold'])) {
            $this->checkInteger($config, 'rssi_threshold', $out);
        }
        if (isset($config['detection_duration'])) {
            $this->checkPositiveInteger($config, 'detection_duration', $out);
        }

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Find a BLE device',
            'summary' => 'Scan for a named Bluetooth device and complete when it is in range.',
            'disabled_reason' => null,
        ];
    }
}
