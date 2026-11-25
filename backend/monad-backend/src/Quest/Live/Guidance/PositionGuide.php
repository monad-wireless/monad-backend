<?php

declare(strict_types=1);

namespace App\Quest\Live\Guidance;

/**
 * Estimates where the phone is and reports the distance to the goal.
 *
 * On the one registered walk (28 Aug 2026, halves cross-fitted) its "warmer / colder" was right 80 %
 * of the time when the true distance had changed by at least 1.5 m in 6 s.
 */
final class PositionGuide implements Guide
{
    private const FAR_METRES = 14.0;
    /** Once in, a player must clearly leave before the zone is taken away again. */
    private const LEAVE_MARGIN = 1.5;

    public function __construct(private readonly float $zoneRadius = 4.0)
    {
    }

    public function key(): string
    {
        return 'position';
    }

    public function trendStep(): float
    {
        return 0.7;
    }

    public function hint(RadioModel $model, array $observations, array $target, array $others, bool $wasInZone): ?Hint
    {
        $at = $model->locate($observations);
        if ($at === null) {
            return null;
        }
        $distance = hypot($at[0] - $target[0], $at[1] - $target[1]);

        return new Hint(
            closeness: 1.0 - min($distance, self::FAR_METRES) / self::FAR_METRES,
            measure: $distance,
            inZone: $distance <= $this->zoneRadius + ($wasInZone ? self::LEAVE_MARGIN : 0.0),
            position: $at,
        );
    }
}
