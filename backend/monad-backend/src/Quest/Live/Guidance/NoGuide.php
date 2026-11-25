<?php

declare(strict_types=1);

namespace App\Quest\Live\Guidance;

/** The control arm of a guidance trial: the player searches by eye. */
final class NoGuide implements Guide
{
    public function key(): string
    {
        return 'none';
    }

    public function trendStep(): float
    {
        return INF;
    }

    public function hint(RadioModel $model, array $observations, array $target, array $others, bool $wasInZone): ?Hint
    {
        return null;
    }
}
