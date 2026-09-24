<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * What one step type accepts in its `config`, in one place (IP-157).
 *
 * Before this interface the rules lived only in ValidStepConfigValidator, attached only to the
 * API's create DTO, so the admin form and every MCP write bypassed them and an `observe` step
 * without `min_readings` could reach a phone. Now the validator, the quest builder's typed form,
 * its palette and `lab_quest_write` all read the same class per type.
 *
 * `validate()` returns plain sentences rather than Symfony violations so it can run without the
 * Validator component: the builder's preflight and the MCP tool call it directly.
 */
interface StepSchema
{
    public function type(): QuestStepType;

    /** @return list<FieldSpec> */
    public function fields(): array;

    /**
     * @param array<string, mixed> $config
     * @return list<string> human-readable violation messages, empty when the config is valid
     */
    public function validate(array $config): array;

    /**
     * The palette entry the builder shows when adding a step. `disabled_reason` is a sentence
     * when this deployment cannot run the step, null otherwise.
     *
     * @return array{title: string, summary: string, disabled_reason: ?string}
     */
    public function palette(): array;
}
