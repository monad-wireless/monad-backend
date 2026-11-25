<?php

declare(strict_types=1);

namespace App\Quest\Live\Guidance;

/**
 * Turns what the nodes hear into "how close is the player to the goal".
 *
 * A guide is one arm of a guidance trial. `trendStep` is the change of `measure` that counts as
 * warmer or colder; below it the guide says nothing about direction.
 */
interface Guide
{
    public function key(): string;

    public function trendStep(): float;

    /**
     * @param list<array{0: float, 1: float, 2: float}> $observations
     * @param array{0: float, 1: float} $target
     * @param list<array{0: float, 1: float}> $others the other possible goals
     */
    public function hint(RadioModel $model, array $observations, array $target, array $others, bool $wasInZone): ?Hint;
}
