<?php

declare(strict_types=1);

namespace App\Quest\Schema;

/**
 * The violation sentences, with the `{{ placeholder }}` shape the ValidStepConfig constraint
 * has always used, so the messages an operator sees are identical whether they come from the
 * API DTO, the admin form, the MCP tool or the builder's preflight.
 */
final class Messages
{
    public const INVALID_TYPE = 'Invalid step type "{{ type }}".';
    public const MISSING_FIELD = 'Missing required field "{{ field }}" for step type "{{ type }}".';
    public const INVALID_FIELD_TYPE = 'Field "{{ field }}" must be {{ expected }}, {{ actual }} given for step type "{{ type }}".';
    public const INVALID_MAC_ADDRESS = 'Field "{{ field }}" must be a valid MAC address (format: XX:XX:XX:XX:XX:XX) for step type "{{ type }}".';
    public const INVALID_VALUE = 'Field "{{ field }}" has invalid value for step type "{{ type }}": {{ reason }}.';

    /** @param array<string, string> $parameters keys without braces, e.g. ['field' => 'prompt'] */
    public static function format(string $template, array $parameters): string
    {
        $replace = [];
        foreach ($parameters as $key => $value) {
            $replace['{{ ' . $key . ' }}'] = $value;
        }

        return strtr($template, $replace);
    }
}
