<?php

namespace App\Dto\Quest;

use App\Validator\Constraints\ValidStepConfig;
use Symfony\Component\Validator\Constraints as Assert;

class QuestCreateStepDto
{
    public const VALID_STEP_TYPES = [
        'start',
        'wait',
        'scan_qr',
        'connect_to_ap',
        'walk_to',
        'find_ble_device',
        'sensor_capture',
        'ble_advertise',
        'probe',
        'observe',
        'finish'
    ];

    #[Assert\NotBlank(message: 'Step name is required')]
    #[Assert\Length(max: 255, maxMessage: 'Step name cannot be longer than {{ limit }} characters')]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Step type is required')]
    #[Assert\Choice(
        choices: self::VALID_STEP_TYPES,
        message: 'Invalid step type. Allowed types: start, wait, scan_qr, connect_to_ap, walk_to, find_ble_device, sensor_capture, ble_advertise, probe, observe, finish'
    )]
    public ?string $type = null;

    #[Assert\NotNull(message: 'Step order is required')]
    #[Assert\PositiveOrZero(message: 'Step order must be a positive number or zero')]
    public ?int $order = null;

    #[Assert\NotNull(message: 'Step configuration is required')]
    #[Assert\Type(type: 'array', message: 'Config must be an array')]
    #[ValidStepConfig]
    public ?array $config = [];
}
