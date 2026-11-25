<?php

declare(strict_types=1);

namespace App\Quest\Live\Guidance;

/**
 * Compares the pattern over the nodes with the pattern expected at the goal. No position, and the
 * handset's own power cancels.
 *
 * On the same walk its direction was right 64 % of the time, but at a card it ranked the true card
 * first at 9 of 17 and in the best three at 15 of 17, so "in the zone" here means a top-three rank.
 */
final class PatternGuide implements Guide
{
    private const CLOSE_DB = 2.0;
    private const FAR_DB = 8.0;
    private const ZONE_RANK = 2;

    public function key(): string
    {
        return 'pattern';
    }

    public function trendStep(): float
    {
        return 0.4;
    }

    public function hint(RadioModel $model, array $observations, array $target, array $others, bool $wasInZone): ?Hint
    {
        $mismatch = $model->mismatch($observations, $target[0], $target[1]);
        if ($mismatch === null) {
            return null;
        }
        $better = 0;
        foreach ($others as [$x, $y]) {
            $other = $model->mismatch($observations, $x, $y);
            $better += $other !== null && $other < $mismatch ? 1 : 0;
        }
        $clamped = max(self::CLOSE_DB, min(self::FAR_DB, $mismatch));

        return new Hint(
            closeness: 1.0 - ($clamped - self::CLOSE_DB) / (self::FAR_DB - self::CLOSE_DB),
            measure: $mismatch,
            inZone: $better <= self::ZONE_RANK,
        );
    }
}
