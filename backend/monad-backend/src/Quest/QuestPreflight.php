<?php

declare(strict_types=1);

namespace App\Quest;

use App\Enum\QuestStepType;

/**
 * The warnings an author sees before saving a quest (IP-157). Warnings never block a save;
 * violations (the step schemas, `QuestSpecMapper::routeViolations()`) do.
 *
 * Pure over arrays on purpose: the spec is the `lab_quest_write` shape and the mirror is a list
 * of `{key, kind, room, synced_at}` rows the caller read out of `lab_placements`. No repository,
 * no clock of its own, so every rule is a unit test with no fixture.
 *
 * The first block repeats every warning `LabTools::questWrite()` emits today, sentence for
 * sentence, so the builder and the MCP tool never disagree about what is worth saying. The
 * second block is the five checks the proposal adds plus the empty mirror.
 */
final class QuestPreflight
{
    public const PAYLOAD_LIMIT_BYTES = 20 * 1024;

    /**
     * The key a target value resolves to in the mirror: the same folding the handset
     * (`ProbeConfig.codeKey`), the portal (`marker_key`) and `lab quest-check` (`code_key`) apply.
     * Lowercase, query and fragment stripped, trailing slash removed, last path segment taken; a
     * URL on a host other than monad.dubec.dev resolves to nothing.
     */
    public static function targetKey(string $raw): string
    {
        $s = trim($raw);
        $s = explode('?', $s, 2)[0];
        $s = explode('#', $s, 2)[0];
        $s = rtrim($s, '/');
        if ($s === '') {
            return '';
        }
        if (str_contains($s, '://')) {
            $s = explode('://', $s, 2)[1];
            $slash = strpos($s, '/');
            $host = $slash === false ? $s : substr($s, 0, $slash);
            if (strtolower($host) !== 'monad.dubec.dev') {
                return '';
            }
            $s = $slash === false ? '' : substr($s, $slash + 1);
        }
        $segments = explode('/', $s);

        return strtolower((string) end($segments));
    }

    /**
     * @param array<string, mixed> $spec the `lab_quest_write` shape; steps normalised or not
     * @param list<array{key: string, kind: string, room?: ?string, synced_at?: ?\DateTimeInterface}> $mirror
     * @return array{warnings: list<string>, required_capabilities: list<string>}
     */
    public function run(array $spec, array $mirror, ?\DateTimeInterface $questUpdatedAt = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $steps = QuestSpecMapper::normaliseSteps(is_array($spec['steps'] ?? null) ? $spec['steps'] : []);

        $warnings = [];
        $capabilities = [];
        $hasProbe = false;
        $broadcastDeclared = false;
        $advertiseSteps = [];

        // ── what lab_quest_write says today ─────────────────────────────────────────────────
        foreach ($steps as $i => $step) {
            $type = QuestStepType::tryFrom($step['type']);
            $config = $step['config'];

            if ($type === QuestStepType::CONNECT_TO_AP) {
                $warnings[] = sprintf(
                    'Step %d asks the phone to join an access point. None exists on this deployment '
                    . '(monitor-mode injection, no AP), so the run will abort at that step.',
                    $i,
                );
            }

            if ($type === QuestStepType::SCAN_QR && ($config['expected_value'] ?? '') === '') {
                $warnings[] = sprintf('Step %d is a scan with no expected_value: it matches any code.', $i);
            }

            if ($type === QuestStepType::BLE_ADVERTISE) {
                $capabilities[] = 'ble.advertise';
                $advertiseSteps[] = $i;
                $warnings[] = sprintf(
                    'Step %d broadcasts the lab identity frame. iOS honours it only in the '
                    . 'foreground, and the quest now requires the ble.advertise capability.',
                    $i,
                );
            }

            if ($type === QuestStepType::PROBE) {
                $capabilities[] = 'ble.advertise';
                $capabilities[] = 'camera.qr';
                $hasProbe = true;

                if (($config['targets'] ?? []) === []) {
                    $warnings[] = sprintf('Step %d is a probe with no targets: nothing can satisfy it.', $i);
                }
                foreach ((array) ($config['targets'] ?? []) as $t) {
                    if (!is_array($t) || !in_array($t['kind'] ?? null, ['card', 'node'], true)) {
                        $warnings[] = sprintf(
                            'Step %d has a target with no kind (card|node). A dwell at a node sits '
                            . 'at zero distance from one link end and cannot be pooled with one on '
                            . 'open floor, so the analysis needs the tag.',
                            $i,
                        );
                        break;
                    }
                }
            }

            if ($type === QuestStepType::START) {
                $features = (array) ($config['features'] ?? []);
                $broadcastDeclared = ($features['broadcast'] ?? false) === true;
            }
        }

        if ($hasProbe && !$broadcastDeclared) {
            $warnings[] = 'This quest has probe steps but its start step does not declare '
                . 'features.broadcast. Every feature defaults to false, so the identity frame will '
                . 'never go on air and each dwell records a participant standing still while no '
                . 'receiver can hear them. Add {"features": {"broadcast": true}} to the start step.';
        }

        // ── the builder's own checks ────────────────────────────────────────────────────────
        if ($broadcastDeclared) {
            foreach ($advertiseSteps as $i) {
                $warnings[] = sprintf(
                    'Step %d is a ble_advertise step but the start step already declares '
                    . 'features.broadcast: the frame is on air for the whole session, so this step '
                    . 'adds nothing and its labelled interval no longer equals the on-air one.',
                    $i,
                );
            }
        }

        $byKey = [];
        foreach ($mirror as $row) {
            $byKey[strtolower((string) $row['key'])] = $row;
        }

        $named = false;
        foreach ($steps as $i => $step) {
            if ($step['type'] !== QuestStepType::PROBE->value) {
                continue;
            }
            foreach ((array) ($step['config']['targets'] ?? []) as $t) {
                if (!is_array($t) || !isset($t['value'])) {
                    continue;
                }
                $named = true;
                $key = self::targetKey((string) $t['value']);
                $row = $byKey[$key] ?? null;
                if ($row === null) {
                    if ($mirror !== []) {
                        $warnings[] = sprintf(
                            'Step %d names target "%s", which is not in the placement mirror for this floor: '
                            . 'no surveyed position, so a matched scan yields no location.',
                            $i,
                            (string) $t['value'],
                        );
                    }
                    continue;
                }
                $synced = $row['synced_at'] ?? null;
                if ($questUpdatedAt !== null && $synced instanceof \DateTimeInterface && $synced < $questUpdatedAt) {
                    $warnings[] = sprintf(
                        'Step %d names target "%s", whose mirror row (synced %s) is older than this quest '
                        . '(updated %s). Re-run lab placements-export before trusting the position.',
                        $i,
                        (string) $t['value'],
                        $synced->format(\DateTimeInterface::ATOM),
                        $questUpdatedAt->format(\DateTimeInterface::ATOM),
                    );
                }
            }
        }

        if ($mirror === [] && $named) {
            $warnings[] = 'The placement mirror is empty: no placements synced yet, run lab placements-export. '
                . 'Targets cannot be checked against a surveyed position.';
        }

        $from = self::parse($spec['available_from'] ?? null);
        $to = self::parse($spec['available_to'] ?? null);
        if ($from !== null && $to !== null && $to <= $from) {
            $warnings[] = sprintf(
                'The window is %s: available_to (%s) is not after available_from (%s), so nobody can ever run this quest.',
                $to == $from ? 'empty' : 'inverted',
                $to->format(\DateTimeInterface::ATOM),
                $from->format(\DateTimeInterface::ATOM),
            );
        } elseif ($to !== null && $to < $now) {
            $warnings[] = sprintf('available_to (%s) is in the past: the quest is hidden as saved.', $to->format(\DateTimeInterface::ATOM));
        }

        $bytes = strlen((string) json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($bytes > self::PAYLOAD_LIMIT_BYTES) {
            $warnings[] = sprintf(
                'The quest payload is %d kB, over the %d kB preflight limit; '
                . 'trim the step descriptions or the target list.',
                intdiv($bytes, 1024),
                intdiv(self::PAYLOAD_LIMIT_BYTES, 1024),
            );
        }

        return [
            'warnings' => $warnings,
            'required_capabilities' => array_values(array_unique($capabilities)),
        ];
    }

    private static function parse(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
