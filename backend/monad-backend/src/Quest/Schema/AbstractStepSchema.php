<?php

declare(strict_types=1);

namespace App\Quest\Schema;

/**
 * The per-field checks every schema composes its rules from.
 *
 * These are the helpers ValidStepConfigValidator carried before IP-157, moved verbatim: same
 * key semantics (`isset` for presence, so a null value counts as absent), same type tests
 * (`is_int`, never a numeric string), same sentences. Each helper appends to `$out` and returns
 * whether the value passed, so a caller can stop where the old code returned early.
 */
abstract class AbstractStepSchema implements StepSchema
{
    /**
     * Every step may carry a `description`: the sentence the app shows under the step title.
     * Declared once here so the builder renders it on every typed form.
     */
    protected function descriptionField(): FieldSpec
    {
        return new FieldSpec('description', FieldSpec::KIND_STRING, false, help: 'Shown to the participant under the step title.');
    }

    /**
     * `description` was never validated before IP-157 and is not required now; when it is
     * present it has to be a string, because the app renders it as one.
     *
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function optionalDescription(array $config, array &$out): void
    {
        if (isset($config['description'])) {
            $this->checkString($config, 'description', $out);
        }
    }

    protected function typeValue(): string
    {
        return $this->type()->value;
    }

    /** @param array<string, string> $parameters */
    protected function message(string $template, array $parameters): string
    {
        return Messages::format($template, $parameters + ['type' => $this->typeValue()]);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function missing(string $field, array &$out): void
    {
        $out[] = $this->message(Messages::MISSING_FIELD, ['field' => $field]);
    }

    /** @param list<string> $out */
    protected function wrongType(string $field, string $expected, mixed $actual, array &$out): void
    {
        $out[] = $this->message(Messages::INVALID_FIELD_TYPE, [
            'field' => $field,
            'expected' => $expected,
            'actual' => gettype($actual),
        ]);
    }

    /** @param list<string> $out */
    protected function invalid(string $field, string $reason, array &$out): void
    {
        $out[] = $this->message(Messages::INVALID_VALUE, ['field' => $field, 'reason' => $reason]);
    }

    /**
     * Required, a string, and not blank once trimmed.
     *
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function requireString(array $config, string $field, array &$out): bool
    {
        if (!isset($config[$field])) {
            $this->missing($field, $out);

            return false;
        }

        if (!is_string($config[$field])) {
            $this->wrongType($field, 'a string', $config[$field], $out);

            return false;
        }

        if (trim($config[$field]) === '') {
            $this->invalid($field, 'cannot be empty', $out);

            return false;
        }

        return true;
    }

    /**
     * Present already; must be a string (blank allowed, as before).
     *
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function checkString(array $config, string $field, array &$out): bool
    {
        if (!is_string($config[$field])) {
            $this->wrongType($field, 'a string', $config[$field], $out);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function requirePositiveInteger(array $config, string $field, array &$out): bool
    {
        if (!isset($config[$field])) {
            $this->missing($field, $out);

            return false;
        }

        return $this->checkPositiveInteger($config, $field, $out);
    }

    /**
     * Present already; must be an int (not a numeric string) and greater than zero.
     *
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function checkPositiveInteger(array $config, string $field, array &$out): bool
    {
        if (!$this->checkInteger($config, $field, $out)) {
            return false;
        }

        if ($config[$field] <= 0) {
            $this->invalid($field, 'must be a positive integer', $out);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function checkInteger(array $config, string $field, array &$out): bool
    {
        if (!is_int($config[$field])) {
            $this->wrongType($field, 'an integer', $config[$field], $out);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function checkNumber(array $config, string $field, array &$out): bool
    {
        if (!is_numeric($config[$field])) {
            $this->wrongType($field, 'a number', $config[$field], $out);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function checkPositiveNumber(array $config, string $field, array &$out): bool
    {
        if (!$this->checkNumber($config, $field, $out)) {
            return false;
        }

        if ((float) $config[$field] <= 0) {
            $this->invalid($field, 'must be a positive number', $out);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $out
     */
    protected function checkBool(array $config, string $field, array &$out): bool
    {
        if (!is_bool($config[$field])) {
            $this->wrongType($field, 'a boolean', $config[$field], $out);

            return false;
        }

        return true;
    }
}
