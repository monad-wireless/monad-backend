<?php

namespace App\Join;

/**
 * The consent text shown on /join, beside its version (IP-157).
 *
 * The two travel together on purpose: `beta_signups.consent_version` records which wording an
 * applicant ticked, so a change to TEXT is a change to VERSION in the same edit. The retention
 * number is not part of the wording: it is `MONAD_BETA_RETENTION_DAYS`, rendered into the `%d`
 * slots at request time, so the text and the purge cannot disagree about it.
 *
 * Controller, purpose and retention are named in the sentence itself, which is what the proposal
 * requires of the checkbox (Key Component 7).
 */
final class JoinConsent
{
    public const VERSION = '2026-09-16';

    /** Two `%d` slots, both the retention window in days. */
    public const TEXT = 'I agree that Jakub Dubec (FIIT STU Bratislava, jakub.dubec@stuba.sk) stores my email '
        . 'address and the answers above to organise MonadCount beta invitations and test sessions. '
        . 'A signup that is not followed by an account in the app is deleted %d days after the '
        . 'invitation, or %d days after signing up if no invitation is sent. I can withdraw at any '
        . 'time by writing to jakub.dubec@stuba.sk.';

    public static function text(int $retentionDays): string
    {
        return sprintf(self::TEXT, $retentionDays, $retentionDays);
    }
}
