<?php

namespace App\Validator\Constraints;

use App\Enum\QuestStepType;
use App\Quest\Schema\StepSchemaRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * A thin dispatcher over App\Quest\Schema (IP-157).
 *
 * The rules used to live in this class, attached only to the API's create DTO. They moved to one
 * StepSchema per step type so the admin form, `lab_quest_write` and the quest builder share
 * them; this validator only finds the step type on the object being validated and hands the
 * config to the matching schema.
 *
 * The type is read from `getType()` when the object has one (QuestStep, an enum) or from a public
 * `type` property (QuestCreateStepDto, a string). The old `$object->type` read would have thrown
 * on the entity's private property, which is why the constraint could never have sat on it.
 */
class ValidStepConfigValidator extends ConstraintValidator
{
    private readonly StepSchemaRegistry $registry;

    /**
     * Optional so the Validator component's default factory (`new $class()`) and the unit tests
     * can build it; the container injects the shared registry.
     */
    public function __construct(?StepSchemaRegistry $registry = null)
    {
        $this->registry = $registry ?? new StepSchemaRegistry();
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidStepConfig) {
            throw new UnexpectedTypeException($constraint, ValidStepConfig::class);
        }

        if ($value === null || !is_array($value)) {
            return;
        }

        $type = $this->typeOf($this->context->getObject());
        if ($type === null) {
            return;
        }

        if (is_string($type)) {
            $stepType = QuestStepType::tryFrom($type);
            if ($stepType === null) {
                $this->context->buildViolation($constraint->messageInvalidType)
                    ->setParameter('{{ type }}', $type)
                    ->addViolation();

                return;
            }
            $type = $stepType;
        }

        foreach ($this->registry->for($type)->validate($value) as $message) {
            // Already interpolated by the schema; no parameters left to substitute.
            $this->context->buildViolation($message)->addViolation();
        }
    }

    private function typeOf(?object $object): QuestStepType|string|null
    {
        if ($object === null) {
            return null;
        }

        if (method_exists($object, 'getType')) {
            $type = $object->getType();
        } elseif (property_exists($object, 'type') && (new \ReflectionProperty($object, 'type'))->isPublic()) {
            $type = $object->type;
        } else {
            return null;
        }

        if ($type instanceof QuestStepType || is_string($type)) {
            return $type;
        }

        return null;
    }
}
