<?php

namespace App\Enum;

/**
 * The beta signup pipeline (IP-157): new -> invited -> registered, plus declined and withdrawn.
 *
 * `registered` is set by POST /api/auth/register when the new account's email matches the
 * signup case-insensitively. `withdrawn` scrubs the row in place and is terminal; the row keeps
 * its dates so the funnel still counts it.
 */
enum BetaSignupStatus: string
{
    case NEW = 'new';
    case INVITED = 'invited';
    case REGISTERED = 'registered';
    case DECLINED = 'declined';
    case WITHDRAWN = 'withdrawn';
}
