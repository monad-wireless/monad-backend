<?php

declare(strict_types=1);

namespace App\Quest\Live\RadioHunt;

use App\Quest\Live\LiveEngineSpec;
use App\Quest\QuestPreflight;
use App\Quest\Schema\ProbeSchema;

/**
 * Options of the radio hunt.
 *
 * Required: `floor` (the placement mirror's floor) and `targets` (the probe shape: value, label,
 * room, kind). Everything else has a default, listed in {@see self::DEFAULTS}.
 */
final class RadioHuntSpec implements LiveEngineSpec
{
    public const KEY = 'radio_hunt';

    public const DEFAULTS = [
        // how many treasures one run looks for
        'rounds' => 3,
        'dwell_seconds' => 30,
        // guidance arms and their weights; one is drawn per treasure
        'guidance' => ['position' => 1],
        'zone_radius_m' => 4.0,
        // the next treasure is at least this far from the last one
        'min_separation_m' => 6.0,
        // "give me another one" appears after this long
        'skip_after_seconds' => 150,
        'crew_question' => 'How many people play with this phone?',
        // null asks nothing at a found card
        'count_question' => 'How many people do you see around you now? Not counting your group.',
        'count_max' => 80,
        // players of the same pool are sent to different treasures
        'pool' => 'radio-hunt',
        'window_seconds' => 4.0,
        'max_age_seconds' => 8.0,
    ];

    public const ARMS = ['position', 'pattern', 'none'];

    public function key(): string
    {
        return self::KEY;
    }

    public function summary(): string
    {
        return 'Find printed cards by radio only: the fleet hears the phone and the screen says warmer or colder.';
    }

    public function problems(array $options): array
    {
        $out = [];
        if (!is_string($options['floor'] ?? null) || trim($options['floor']) === '') {
            $out[] = 'options.floor must name a floor of the placement mirror';
        }

        $targets = $options['targets'] ?? null;
        if (!is_array($targets) || $targets === []) {
            $out[] = 'options.targets must be a non-empty list of targets';
            $targets = [];
        }
        $keys = [];
        foreach (array_values($targets) as $i => $target) {
            if (!is_array($target)) {
                $out[] = sprintf('options.targets[%d] must be an object', $i);
                continue;
            }
            foreach (['value', 'label', 'room'] as $field) {
                if (!is_string($target[$field] ?? null) || trim($target[$field]) === '') {
                    $out[] = sprintf('options.targets[%d].%s must be a non-empty string', $i, $field);
                }
            }
            if (!in_array($target['kind'] ?? null, ProbeSchema::KINDS, true)) {
                $out[] = sprintf('options.targets[%d].kind must be one of: card, node', $i);
            }
            $key = QuestPreflight::targetKey((string) ($target['value'] ?? ''));
            if ($key !== '' && isset($keys[$key])) {
                $out[] = sprintf('options.targets names "%s" twice', $key);
            }
            $keys[$key] = true;
        }

        foreach (['rounds', 'dwell_seconds', 'skip_after_seconds', 'count_max'] as $field) {
            if (array_key_exists($field, $options) && (!is_int($options[$field]) || $options[$field] < 1)) {
                $out[] = sprintf('options.%s must be a positive integer', $field);
            }
        }
        $rounds = $options['rounds'] ?? self::DEFAULTS['rounds'];
        if (is_int($rounds) && $targets !== [] && $rounds > count($targets)) {
            $out[] = 'options.rounds must not exceed the number of targets';
        }
        foreach (['zone_radius_m', 'min_separation_m', 'window_seconds', 'max_age_seconds'] as $field) {
            if (array_key_exists($field, $options) && (!is_numeric($options[$field]) || $options[$field] < 0)) {
                $out[] = sprintf('options.%s must be a number, zero or more', $field);
            }
        }
        foreach (['crew_question', 'count_question', 'pool'] as $field) {
            if (isset($options[$field]) && (!is_string($options[$field]) || trim($options[$field]) === '')) {
                $out[] = sprintf('options.%s must be a non-empty string or null', $field);
            }
        }
        if (array_key_exists('guidance', $options)) {
            $arms = $options['guidance'];
            $valid = is_array($arms) && $arms !== [];
            foreach ((array) $arms as $arm => $weight) {
                $valid = $valid && in_array($arm, self::ARMS, true) && is_int($weight) && $weight >= 0;
            }
            if (!$valid || array_sum(array_filter((array) $arms, 'is_int')) < 1) {
                $out[] = 'options.guidance must map arms (position, pattern, none) to whole weights, at least one above zero';
            }
        }

        return $out;
    }

    public function requiredCapabilities(): array
    {
        return ['ble.advertise', 'camera.qr'];
    }

    public function needsBroadcast(): bool
    {
        return true;
    }

    public function scannedValues(array $options): array
    {
        $out = [];
        foreach ((array) ($options['targets'] ?? []) as $target) {
            $value = is_array($target) ? trim((string) ($target['value'] ?? '')) : '';
            if ($value !== '') {
                $label = trim((string) ($target['label'] ?? ''));
                $out[$value] = $label !== '' ? $label : $value;
            }
        }

        return $out;
    }
}
