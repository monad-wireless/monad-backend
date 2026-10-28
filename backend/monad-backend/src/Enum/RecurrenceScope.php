<?php

namespace App\Enum;

/**
 * What a quest's cooldown is counted against (IP-128).
 *
 * The scope is the difference between "you did this today" and "you did this
 * *here* today". `PER_DEVICE` is what makes an evergreen fleet quest work: the
 * same quest is independently replayable at monad01 and monad04, which is also
 * the behaviour that produces spatial spread in the data instead of twelve runs
 * at whichever node is nearest the door.
 */
enum RecurrenceScope: string
{
    /** Cooldown counted per (user, quest, device). The fleet-quest default. */
    case PER_DEVICE = 'per_device';

    /** Cooldown counted per (user, quest), wherever it was run. */
    case PER_QUEST = 'per_quest';
}
