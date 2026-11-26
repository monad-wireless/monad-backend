<?php

namespace App\Enum;

/**
 * Which way a participant crossed a zone boundary.
 *
 * Stored resolved: the printed code may be a toggle, but toggle resolution happens on the
 * participant's own handset against their own scan history, and `toggle` never reaches the wire.
 * A server that had to resolve toggles would need a shared counter that twelve phones could race.
 */
enum GroundTruthDirection: string
{
    case IN = 'in';
    case OUT = 'out';

    public static function tryFromWire(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /** +1 when someone enters, -1 when they leave. The term of the cumulative occupancy sum. */
    public function delta(): int
    {
        return $this === self::IN ? 1 : -1;
    }
}
