<?php

namespace App\Enum;

/** The phone a /join applicant says they have (IP-157). `unsure` is an honest answer. */
enum BetaPlatform: string
{
    case IOS = 'ios';
    case ANDROID = 'android';
    case UNSURE = 'unsure';
}
