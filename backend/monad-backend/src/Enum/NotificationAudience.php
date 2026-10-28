<?php

namespace App\Enum;

/** Who a notification is addressed to (IP-157). Resolution to users is Phase 4's job. */
enum NotificationAudience: string
{
    case ALL = 'all';
    /** Accounts with `users.cohort = 'beta'`. */
    case BETA = 'beta';
    /** Accounts holding ROLE_SUPERADMIN. */
    case OPERATORS = 'operators';
}
