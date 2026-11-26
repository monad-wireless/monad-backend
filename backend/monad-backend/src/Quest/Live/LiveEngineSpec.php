<?php

declare(strict_types=1);

namespace App\Quest\Live;

/**
 * The authoring half of an engine: which options a quest may give it.
 *
 * Stateless and without dependencies, like the step schemas, because it runs where no container
 * exists (the Validator component, the MCP tool, unit tests).
 */
interface LiveEngineSpec
{
    public function key(): string;

    public function summary(): string;

    /**
     * @param array<string, mixed> $options
     * @return list<string> sentences; empty when the options are valid
     */
    public function problems(array $options): array;

    /**
     * Capability tokens this engine needs beyond {@see LiveProtocol::CAPABILITY}.
     *
     * @return list<string>
     */
    public function requiredCapabilities(): array;

    /** Whether the engine needs the phone on air, so a quest without `features.broadcast` is warned about. */
    public function needsBroadcast(): bool;

    /**
     * The printed codes a player may have to scan, value to label, so a card named only by a live
     * step is still a known marker.
     *
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    public function scannedValues(array $options): array;
}
