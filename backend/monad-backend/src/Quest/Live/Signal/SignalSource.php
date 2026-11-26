<?php

declare(strict_types=1);

namespace App\Quest\Live\Signal;

/** What the sensors say about one phone right now. A port, so an engine is tested without a fleet. */
interface SignalSource
{
    /**
     * The newest readings of one subject: the `window` seconds that end at the newest reading, and
     * only when that reading is at most `maxAge` seconds old.
     *
     * Anchored on the newest reading rather than on `now`, because a reading is a few seconds late
     * and a window anchored on `now` would lose its tail on every sync.
     *
     * @return list<array{node: string, rssi: float}> the median RSSI per node, loudest first
     */
    public function nodeMedians(string $subject, float $now, float $window, float $maxAge): array;
}
