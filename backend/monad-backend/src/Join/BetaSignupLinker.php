<?php

namespace App\Join;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaSignupStatus;
use App\Repository\BetaSignupRepository;

/**
 * Ties a freshly registered account to the beta signup with the same email (IP-157).
 *
 * Called by POST /api/auth/register after the user is persisted and before the flush, so the
 * account and the status change land in one transaction. A signup in `new` or `invited` moves
 * to `registered` and the account gets `cohort = 'beta'`; every other status is left alone and
 * null is returned. Nothing here flushes: the caller owns the unit of work, which is also what
 * makes this reusable from a console command.
 */
final class BetaSignupLinker
{
    public function __construct(private readonly BetaSignupRepository $signups)
    {
    }

    /**
     * Whether an operator has invited this account's email from the onboarding desk.
     *
     * Beta access is invitation only, so this is the approval: POST /api/auth/register activates
     * an account at once when it is true and leaves it PENDING otherwise. It must be asked BEFORE
     * link(), which moves the signup on to `registered`.
     */
    public function isInvited(User $user): bool
    {
        $email = $user->getEmail();
        if ($email === null || trim($email) === '') {
            return false;
        }

        return $this->signups->findByEmail($email)?->getStatus() === BetaSignupStatus::INVITED;
    }

    public function link(User $user, ?\DateTimeImmutable $now = null): ?BetaSignup
    {
        $email = $user->getEmail();
        if ($email === null || trim($email) === '') {
            return null;
        }

        $signup = $this->signups->findByEmail($email);
        if ($signup === null) {
            return null;
        }
        if (!in_array($signup->getStatus(), [BetaSignupStatus::NEW, BetaSignupStatus::INVITED], true)) {
            return null;
        }

        $signup->markRegistered($user, $now);
        $user->setCohort('beta');

        return $signup;
    }
}
