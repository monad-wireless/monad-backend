<?php

declare(strict_types=1);

namespace App\Quest\Live;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One server-driven quest mechanic.
 *
 * An engine is a state machine over a plain array. The service owns the transaction, the row lock,
 * event de-duplication and persistence, so an engine holds rules and nothing else. It must accept
 * any event in any phase: a phone retries, and an event that arrives late is ignored, never an error.
 *
 * Its static half (which options it takes, what it needs from a phone) is the
 * {@see LiveEngineSpec} of the same key, which has no dependencies and validates quest authoring.
 */
#[AutoconfigureTag('app.live_engine')]
interface LiveEngine
{
    public function key(): string;

    /** @return array<string, mixed> the state a new run starts in */
    public function open(LiveContext $context): array;

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed> the next state
     */
    public function handle(array $state, LiveEvent $event, LiveContext $context): array;

    /**
     * Time passed. Called once per sync, after the events.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function advance(array $state, LiveContext $context): array;

    /** @param array<string, mixed> $state */
    public function view(array $state, LiveContext $context): LiveView;

    /** @param array<string, mixed> $state */
    public function isDone(array $state): bool;

    /**
     * What the finished step reports as its `step_data`.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function result(array $state): array;
}
