<?php

declare(strict_types=1);

namespace App\Quest\Live\Guidance;

/**
 * One guide's answer. `closeness` runs from 0 (far) to 1 (there) and `measure` is the raw quantity
 * it came from (metres or dB), kept for the log and for the trend.
 */
final class Hint
{
    public function __construct(
        public readonly float $closeness,
        public readonly float $measure,
        public readonly bool $inZone,
        /** @var array{0: float, 1: float}|null */
        public readonly ?array $position = null,
    ) {
    }
}
