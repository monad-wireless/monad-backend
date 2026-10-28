<?php

namespace App\Enum;

/** Availability for sessions at FIIT, as stated on /join (IP-157). */
enum BetaAvailability: string
{
    case YES = 'yes';
    case SOMETIMES = 'sometimes';
    case REMOTE = 'remote';
}
