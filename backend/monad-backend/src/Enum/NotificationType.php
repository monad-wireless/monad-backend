<?php

namespace App\Enum;

/**
 * Two types by the researcher's call (IP-157).
 *
 * `general` is on once the OS permission is granted. `quest_callout` is promotional content
 * under App Store Review Guideline 4.5.4 and Google Play's notification policy, so it is off by
 * default and needs the explicit in-app opt-in stored in `users.notify_callouts`.
 */
enum NotificationType: string
{
    case GENERAL = 'general';
    case QUEST_CALLOUT = 'quest_callout';
}
