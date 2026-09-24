<?php

namespace App\Validator\Constraints;

use App\Quest\Schema\Messages;
use Symfony\Component\Validator\Constraint;

/**
 * A step `config` matches its step type's schema (App\Quest\Schema).
 *
 * Applied to QuestStep::$config (the entity, so the admin form and every MCP write validate) and
 * to QuestCreateStepDto::$config (the API). The sentences are the schema package's, referenced
 * here so the two cannot drift.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ValidStepConfig extends Constraint
{
    public string $messageInvalidType = Messages::INVALID_TYPE;
    public string $messageMissingField = Messages::MISSING_FIELD;
    public string $messageInvalidFieldType = Messages::INVALID_FIELD_TYPE;
    public string $messageInvalidMacAddress = Messages::INVALID_MAC_ADDRESS;
    public string $messageInvalidValue = Messages::INVALID_VALUE;

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
