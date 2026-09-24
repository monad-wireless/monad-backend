<?php

declare(strict_types=1);

namespace App\Lab\Contract;

/**
 * The IP-162 Counting contracts as this backend enforces them: identifiers, the observe v2
 * configuration, the sweep summary a completion carries, and the evidence manifest a seal
 * verifies.
 *
 * The schemas of record are JSON Schema documents in
 * `monad-knowledge/monad_knowledge/lab/contracts/schemas/`; the Python package there is the
 * reference reader and `tests/fixtures/ip162/` is a verbatim copy of its fixtures. This class
 * carries the subset of rules the backend is responsible for and mirrors the reference's problem
 * strings closely enough that a fixture's `expect_problem` substring matches here too.
 */
final class CountingContracts
{
    public const SCHEMA_OBSERVE_V2 = 'monad-quest/observe/v2';
    public const SCHEMA_HEADCOUNT_V3 = 'monad-app/headcount-marker/v3';
    public const SCHEMA_EVIDENCE_MANIFEST_V1 = 'monad-lab/evidence-manifest/v1';
    public const SCHEMA_SWEEP_SUMMARY_V1 = 'monad-lab/sweep-summary/v1';

    /** The software capability a handset must advertise before it is served a sweep quest. */
    public const CAPABILITY_ROOM_SWEEP = 'observe.room_sweep.v1';

    public const MODE_ROOM_SWEEP = 'room_sweep_cumulative';
    public const OBSERVER_EXCLUDE_SELF = 'exclude_self';

    /** Artefacts a sweep recording's manifest must name. */
    public const REQUIRED_SWEEP_ARTIFACTS = ['markers.tsv', 'clock.tsv', 'clock-exchanges.tsv', 'metadata.json'];

    public const COVERAGES = ['complete', 'partial', 'unknown'];
    public const STABILITIES = ['stable', 'changed', 'unknown'];
    public const PHASES = ['not_started', 'active', 'finalised', 'aborted'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const SHA256 = '/^[0-9a-f]{64}$/';
    private const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';
    private const PROTOCOL_ID = '/^[a-z0-9][a-z0-9-]{2,63}$/';
    private const VERSION = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';
    private const NS = '/^-?[0-9]{1,19}$/';
    private const ARTEFACT_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    /** @param array<string, mixed> $config */
    public static function isSweepConfig(array $config): bool
    {
        return ($config['schema'] ?? null) === self::SCHEMA_OBSERVE_V2;
    }

    /**
     * Every reason an observe v2 configuration is refused. Empty means valid, digest included.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function observeConfigProblems(array $config): array
    {
        $out = [];
        if (($config['schema'] ?? null) !== self::SCHEMA_OBSERVE_V2) {
            $out[] = sprintf('schema: %s is not %s', json_encode($config['schema'] ?? null), self::SCHEMA_OBSERVE_V2);
        }
        if (($config['mode'] ?? null) !== self::MODE_ROOM_SWEEP) {
            $out[] = sprintf('mode: %s is not %s', json_encode($config['mode'] ?? null), self::MODE_ROOM_SWEEP);
        }
        if (!is_string($config['prompt'] ?? null) || trim($config['prompt']) === '') {
            $out[] = 'prompt: a non-empty string is required';
        }
        if (!self::matches($config['protocol_id'] ?? null, self::PROTOCOL_ID)) {
            $out[] = 'protocol_id: required, lower-case slug of 3 to 64 characters';
        }
        if (!self::matches($config['protocol_sha256'] ?? null, self::SHA256)) {
            $out[] = 'protocol_sha256: required, 64 lower-case hex characters';
        }
        $rooms = $config['rooms'] ?? null;
        if (!is_array($rooms) || !array_is_list($rooms) || $rooms === []) {
            $out[] = 'rooms: a non-empty list of rooms is required';
        } else {
            foreach ($rooms as $i => $room) {
                foreach (self::roomProblems(is_array($room) ? $room : []) as $problem) {
                    $out[] = sprintf('rooms/%d/%s', $i, $problem);
                }
            }
        }
        $checkpoints = $config['checkpoints'] ?? null;
        if (!is_array($checkpoints)) {
            $out[] = 'checkpoints: required object {min_checkpoints, required_for_complete}';
        } else {
            if (!is_int($checkpoints['min_checkpoints'] ?? null) || $checkpoints['min_checkpoints'] < 0) {
                $out[] = 'checkpoints/min_checkpoints: a non-negative integer is required';
            }
            if (!is_bool($checkpoints['required_for_complete'] ?? null)) {
                $out[] = 'checkpoints/required_for_complete: a boolean is required';
            }
            $extra = array_diff(array_keys($checkpoints), ['min_checkpoints', 'required_for_complete']);
            if ($extra !== []) {
                $out[] = 'checkpoints: unexpected keys ' . implode(', ', $extra);
            }
        }
        if (($config['observer_convention'] ?? null) !== self::OBSERVER_EXCLUDE_SELF) {
            $out[] = sprintf("observer_convention: '%s' was expected", self::OBSERVER_EXCLUDE_SELF);
        }
        if (array_key_exists('max_count', $config) && $config['max_count'] !== null
            && (!is_int($config['max_count']) || $config['max_count'] < 1)) {
            $out[] = 'max_count: null or a positive integer';
        }
        if (array_key_exists('description', $config) && !is_string($config['description'])) {
            $out[] = 'description: a string';
        }
        $allowed = ['schema', 'mode', 'description', 'prompt', 'protocol_id', 'protocol_sha256', 'rooms',
            'checkpoints', 'observer_convention', 'max_count'];
        foreach (array_diff(array_keys($config), $allowed) as $key) {
            $out[] = sprintf('%s: unexpected key', $key);
        }

        if ($out === []) {
            try {
                $expected = CanonicalJson::selfDigest($config, 'protocol_sha256');
            } catch (\InvalidArgumentException $e) {
                return [$e->getMessage()];
            }
            if ($config['protocol_sha256'] !== $expected) {
                $out[] = sprintf('protocol_sha256: does not match the canonical body (expected %s)', $expected);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $room
     * @return list<string>
     */
    private static function roomProblems(array $room): array
    {
        $out = [];
        foreach (['room_id' => self::SLUG, 'floor_id' => self::SLUG] as $field => $pattern) {
            if (!self::matches($room[$field] ?? null, $pattern)) {
                $out[] = sprintf('%s: required, lower-case slug', $field);
            }
        }
        foreach (['geometry_version', 'coverage_version'] as $field) {
            if (!self::matches($room[$field] ?? null, self::VERSION)) {
                $out[] = sprintf('%s: required version token', $field);
            }
        }
        foreach (['label', 'coverage_instructions'] as $field) {
            if (!is_string($room[$field] ?? null) || trim($room[$field]) === '') {
                $out[] = sprintf('%s: a non-empty string is required', $field);
            }
        }
        $allowed = ['room_id', 'floor_id', 'label', 'geometry_version', 'coverage_version', 'coverage_instructions'];
        foreach (array_diff(array_keys($room), $allowed) as $key) {
            // A capacity is the field this rule exists for: it is never a room fact the quest asserts.
            $out[] = sprintf('%s: unexpected key; a room carries identity, versions and instructions only', $key);
        }

        return $out;
    }

    /**
     * The typed summary a sweep step's completion carries in `step_data` (IP-162 §2). A pointer
     * to the evidence, never a second count stream.
     *
     * @param array<string, mixed> $summary
     * @return list<string>
     */
    public static function sweepSummaryProblems(array $summary): array
    {
        $out = [];
        if (($summary['schema'] ?? null) !== self::SCHEMA_SWEEP_SUMMARY_V1) {
            $out[] = sprintf('schema: %s is not %s', json_encode($summary['schema'] ?? null), self::SCHEMA_SWEEP_SUMMARY_V1);
        }
        foreach (['recording_session_id', 'sweep_id', 'step_completion_id'] as $field) {
            if (!self::matches($summary[$field] ?? null, self::UUID)) {
                $out[] = sprintf('%s: a lower-case UUID is required', $field);
            }
        }
        if (!self::matches($summary['protocol_id'] ?? null, self::PROTOCOL_ID)) {
            $out[] = 'protocol_id: required';
        }
        if (!self::matches($summary['protocol_sha256'] ?? null, self::SHA256)) {
            $out[] = 'protocol_sha256: required, 64 lower-case hex characters';
        }
        if (!self::matches($summary['room_id'] ?? null, self::SLUG)) {
            $out[] = 'room_id: required';
        }
        if (!self::matches($summary['coverage_version'] ?? null, self::VERSION)) {
            $out[] = 'coverage_version: required';
        }
        $phase = $summary['phase'] ?? null;
        if (!in_array($phase, self::PHASES, true)) {
            $out[] = 'phase: one of ' . implode(', ', self::PHASES);
        }
        $final = $summary['final_event_id'] ?? null;
        if ($final !== null && !self::matches($final, self::UUID)) {
            $out[] = 'final_event_id: null or a lower-case UUID';
        }
        if (in_array($phase, ['finalised', 'aborted'], true) && $final === null) {
            $out[] = 'final_event_id: required once the sweep is closed';
        }
        $count = $summary['count'] ?? null;
        if ($count !== null && (!is_int($count) || $count < 0)) {
            $out[] = 'count: null or a non-negative integer; never a boolean or a fraction';
        }
        if ($phase === 'finalised' && $count === null) {
            $out[] = 'count: required on a finalised sweep';
        }
        $coverage = $summary['coverage'] ?? null;
        if ($coverage !== null && !in_array($coverage, self::COVERAGES, true)) {
            $out[] = 'coverage: null or one of ' . implode(', ', self::COVERAGES);
        }
        $stability = $summary['occupancy_stability'] ?? null;
        if ($stability !== null && !in_array($stability, self::STABILITIES, true)) {
            $out[] = 'occupancy_stability: null or one of ' . implode(', ', self::STABILITIES);
        }
        foreach (['events_accepted', 'corrections'] as $field) {
            if (!is_int($summary[$field] ?? null) || $summary[$field] < 0) {
                $out[] = sprintf('%s: a non-negative integer is required', $field);
            }
        }

        return $out;
    }

    /**
     * Structural rules of `monad-lab/evidence-manifest/v1`, before any byte is fetched.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public static function evidenceManifestProblems(array $manifest): array
    {
        $out = [];
        if (($manifest['schema'] ?? null) !== self::SCHEMA_EVIDENCE_MANIFEST_V1) {
            $out[] = sprintf('schema: %s is not %s', json_encode($manifest['schema'] ?? null), self::SCHEMA_EVIDENCE_MANIFEST_V1);
        }
        foreach (['recording_session_id', 'enrollment_id', 'quest_id', 'step_completion_id'] as $field) {
            if (!self::matches($manifest[$field] ?? null, self::UUID)) {
                $out[] = sprintf('%s: a lower-case UUID is required', $field);
            }
        }
        $sweeps = $manifest['sweep_ids'] ?? null;
        if (!is_array($sweeps) || !array_is_list($sweeps) || $sweeps === []) {
            $out[] = 'sweep_ids: a non-empty list is required';
        } else {
            foreach ($sweeps as $i => $sweep) {
                if (!self::matches($sweep, self::UUID)) {
                    $out[] = sprintf('sweep_ids/%d: a lower-case UUID is required', $i);
                }
            }
            if (count(array_unique($sweeps)) !== count($sweeps)) {
                $out[] = 'sweep_ids: must be unique';
            }
        }
        if (!is_string($manifest['app_build_id'] ?? null) || $manifest['app_build_id'] === '') {
            $out[] = 'app_build_id: a non-empty string is required';
        }
        $schemas = $manifest['payload_schemas'] ?? null;
        if (!is_array($schemas) || !array_is_list($schemas) || $schemas === []
            || $schemas !== array_values(array_filter($schemas, 'is_string'))) {
            $out[] = 'payload_schemas: a non-empty list of strings is required';
        }
        if (!self::matches($manifest['snapshot_sha256'] ?? null, self::SHA256)) {
            $out[] = 'snapshot_sha256: required, 64 lower-case hex characters';
        }
        $clock = $manifest['clock_domain'] ?? null;
        if (!is_array($clock)) {
            $out[] = 'clock_domain: required object {boot_id, clock_source, monotonic_continuous}';
        } else {
            foreach (['boot_id', 'clock_source'] as $field) {
                if (!is_string($clock[$field] ?? null) || $clock[$field] === '') {
                    $out[] = sprintf('clock_domain/%s: a non-empty string is required', $field);
                }
            }
            if (!is_bool($clock['monotonic_continuous'] ?? null)) {
                $out[] = 'clock_domain/monotonic_continuous: a boolean is required';
            }
        }
        $artifacts = $manifest['artifacts'] ?? null;
        if (!is_array($artifacts) || !array_is_list($artifacts) || $artifacts === []) {
            $out[] = 'artifacts: a non-empty list is required';
        } else {
            $names = [];
            foreach ($artifacts as $i => $artifact) {
                if (!is_array($artifact)) {
                    $out[] = sprintf('artifacts/%d: an object is required', $i);
                    continue;
                }
                if (!self::matches($artifact['name'] ?? null, self::ARTEFACT_NAME)) {
                    $out[] = sprintf('artifacts/%d/name: a filename is required', $i);
                } else {
                    $names[] = $artifact['name'];
                }
                if (!self::matches($artifact['sha256'] ?? null, self::SHA256)) {
                    $out[] = sprintf('artifacts/%d/sha256: 64 lower-case hex characters', $i);
                }
                if (!is_int($artifact['bytes'] ?? null) || $artifact['bytes'] < 0) {
                    $out[] = sprintf('artifacts/%d/bytes: a non-negative integer is required', $i);
                }
                if (!is_string($artifact['content_type'] ?? null) || $artifact['content_type'] === '') {
                    $out[] = sprintf('artifacts/%d/content_type: a non-empty string is required', $i);
                }
            }
            if (count(array_unique($names)) !== count($names)) {
                $out[] = 'artifacts: an artefact name appears twice';
            }
            if (is_array($schemas) && in_array(self::SCHEMA_HEADCOUNT_V3, $schemas, true)) {
                foreach (self::missingSweepArtifacts($manifest) as $missing) {
                    $out[] = sprintf('artifacts: a sweep recording must seal %s', $missing);
                }
            }
        }
        if (!self::matches($manifest['sealed_mono_ns'] ?? null, self::NS)) {
            $out[] = 'sealed_mono_ns: a decimal string of nanoseconds is required';
        }
        if (!is_int($manifest['sealed_wall_ms'] ?? null) || $manifest['sealed_wall_ms'] < 0) {
            $out[] = 'sealed_wall_ms: a non-negative integer is required';
        }
        $allowed = ['schema', 'recording_session_id', 'enrollment_id', 'quest_id', 'step_completion_id', 'sweep_ids',
            'app_build_id', 'payload_schemas', 'snapshot_sha256', 'clock_domain', 'artifacts', 'sealed_mono_ns',
            'sealed_wall_ms'];
        foreach (array_diff(array_keys($manifest), $allowed) as $key) {
            $out[] = sprintf('%s: unexpected key', $key);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public static function missingSweepArtifacts(array $manifest): array
    {
        $named = [];
        foreach ((array) ($manifest['artifacts'] ?? []) as $artifact) {
            if (is_array($artifact) && is_string($artifact['name'] ?? null)) {
                $named[] = $artifact['name'];
            }
        }

        return array_values(array_diff(self::REQUIRED_SWEEP_ARTIFACTS, $named));
    }

    private static function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }
}
