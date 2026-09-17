<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * One schema per QuestStepType case.
 *
 * Constructed without arguments on purpose: the schemas are stateless, and the registry has to
 * be usable where no container exists (ValidStepConfigValidator is instantiated by the Validator
 * component's own factory, unit tests build it directly). `for()` throws on a case with no
 * schema, and the constructor checks exhaustiveness once, so adding an enum case without a
 * schema fails at boot rather than as an \UnhandledMatchError in a request.
 */
final class StepSchemaRegistry
{
    /** @var array<string, StepSchema> keyed by QuestStepType value */
    private array $schemas = [];

    public function __construct()
    {
        foreach ([
            new StartSchema(),
            new WaitSchema(),
            new ScanQrSchema(),
            new ConnectToApSchema(),
            new WalkToSchema(),
            new FindBleDeviceSchema(),
            new SensorCaptureSchema(),
            new BleAdvertiseSchema(),
            new ProbeSchema(),
            new ObserveSchema(),
            new FinishSchema(),
        ] as $schema) {
            $this->schemas[$schema->type()->value] = $schema;
        }

        foreach (QuestStepType::cases() as $case) {
            if (!isset($this->schemas[$case->value])) {
                throw new \LogicException(sprintf('No StepSchema for QuestStepType::%s.', $case->name));
            }
        }
    }

    public function for(QuestStepType $type): StepSchema
    {
        return $this->schemas[$type->value];
    }

    /** @return list<StepSchema> in QuestStepType declaration order */
    public function all(): array
    {
        $out = [];
        foreach (QuestStepType::cases() as $case) {
            $out[] = $this->schemas[$case->value];
        }

        return $out;
    }
}
