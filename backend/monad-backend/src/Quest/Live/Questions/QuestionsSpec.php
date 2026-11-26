<?php

declare(strict_types=1);

namespace App\Quest\Live\Questions;

use App\Quest\Live\LiveEngineSpec;

/**
 * A short questionnaire as a live step: `options.questions` is a list of
 * `{id, prompt, kind: number|choice, min?, max?, options?, record?}`.
 */
final class QuestionsSpec implements LiveEngineSpec
{
    public const KEY = 'questions';

    public function key(): string
    {
        return self::KEY;
    }

    public function summary(): string
    {
        return 'Ask a few questions, one after another: a number or one of some answers.';
    }

    public function problems(array $options): array
    {
        $questions = $options['questions'] ?? null;
        if (!is_array($questions) || $questions === []) {
            return ['options.questions must be a non-empty list'];
        }
        $out = [];
        $ids = [];
        foreach (array_values($questions) as $i => $q) {
            $at = sprintf('options.questions[%d]', $i);
            if (!is_array($q)) {
                $out[] = "$at must be an object";
                continue;
            }
            foreach (['id', 'prompt'] as $field) {
                if (!is_string($q[$field] ?? null) || trim($q[$field]) === '') {
                    $out[] = "$at.$field must be a non-empty string";
                }
            }
            if (isset($ids[$q['id'] ?? ''])) {
                $out[] = "$at.id is used twice";
            }
            $ids[is_string($q['id'] ?? null) ? $q['id'] : ''] = true;
            $kind = $q['kind'] ?? null;
            if ($kind === 'number') {
                if (!is_int($q['min'] ?? 0) || !is_int($q['max'] ?? 100) || ($q['min'] ?? 0) > ($q['max'] ?? 100)) {
                    $out[] = "$at needs whole min and max, min not above max";
                }
                if (isset($q['record']) && $q['record'] !== 'headcount') {
                    $out[] = "$at.record may only be headcount";
                }
            } elseif ($kind === 'choice') {
                $choices = $q['options'] ?? null;
                $valid = is_array($choices) && count($choices) >= 2;
                foreach ((array) $choices as $c) {
                    $valid = $valid && is_array($c) && is_string($c['value'] ?? null) && is_string($c['label'] ?? null);
                }
                if (!$valid) {
                    $out[] = "$at.options must be at least two {value, label} pairs";
                }
            } else {
                $out[] = "$at.kind must be number or choice";
            }
        }

        return $out;
    }

    public function requiredCapabilities(): array
    {
        return [];
    }

    public function needsBroadcast(): bool
    {
        return false;
    }

    public function scannedValues(array $options): array
    {
        return [];
    }
}
