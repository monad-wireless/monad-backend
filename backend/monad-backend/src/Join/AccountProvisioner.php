<?php

declare(strict_types=1);

namespace App\Join;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaSignupStatus;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pre-creates a participant account from a beta signup, in one click on the people desk.
 *
 * The researcher sends a TestFlight link and the sign-in details by email instead of meeting
 * the tester, so the account must exist before the tester opens the app. The account is ACTIVE
 * at once (the operator creating it is the approval), the signup moves new -> invited ->
 * registered through BetaSignupLinker, the path POST /api/auth/register takes, and the account
 * joins the beta cohort. All in one flush.
 *
 * The generated password exists in plaintext only in the returned value. Only the hash is
 * stored. The tester can change it in the app (My account, POST /api/auth/password); nothing
 * forces a change.
 */
final class AccountProvisioner
{
    /** No 0/o, 1/l/i: the tester types this from an email on a phone keyboard. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(
        private readonly UserRepository $users,
        private readonly BetaSignupLinker $linker,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{user: User, password: string, created: bool}
     *
     * @throws \LogicException when the signup is not new or invited (declined, withdrawn,
     *                         already registered: a double click or a second tab)
     */
    public function provisionFromSignup(BetaSignup $signup, User $operator): array
    {
        if (!in_array($signup->getStatus(), [BetaSignupStatus::NEW, BetaSignupStatus::INVITED], true)) {
            throw new \LogicException(sprintf('%s is %s, not waiting for an account.', $signup->getEmail(), $signup->getStatus()->value));
        }

        // A tester who registered in the app before the operator acted already has a (pending)
        // account. Reuse it, never create a second one for the same address.
        $user = $this->users->findOneByEmailInsensitive($signup->getEmail());
        $created = $user === null;
        if ($user === null) {
            // Lower-cased: login matches the stored address exactly and the app only trims, so a
            // `/join` entry with capitals would otherwise be a sign-in the tester cannot hit.
            $user = (new User())->setEmail(mb_strtolower(trim($signup->getEmail())))->setName($signup->getName());
            $this->em->persist($user);
        }
        if ($user->isDeleted()) {
            throw new \LogicException('An anonymised account holds this address. Restore it by hand or use another address.');
        }
        $user->setStatus(UserStatus::ACTIVE);
        $password = $this->setNewPassword($user);

        if ($signup->getStatus() === BetaSignupStatus::NEW) {
            $signup->markInvited($operator);
        }
        $this->linker->link($user);
        $this->em->flush();

        return ['user' => $user, 'password' => $password, 'created' => $created];
    }

    /** A new password for an existing account. Returns the plaintext, once. */
    public function resetPassword(User $user): string
    {
        if ($user->isDeleted()) {
            throw new \LogicException('An anonymised account has no sign-in to reset.');
        }
        $password = $this->setNewPassword($user);
        $this->em->flush();

        return $password;
    }

    /** Three groups of four, e.g. `k7pm-x3qa-9fde`: 14 characters, inside the 8-255 rule. */
    public static function generatePassword(): string
    {
        $groups = [];
        $max = strlen(self::ALPHABET) - 1;
        for ($g = 0; $g < 3; ++$g) {
            $group = '';
            for ($i = 0; $i < 4; ++$i) {
                $group .= self::ALPHABET[random_int(0, $max)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }

    private function setNewPassword(User $user): string
    {
        $password = self::generatePassword();
        $user->setPassword($this->hasher->hashPassword($user, $password));

        return $password;
    }
}
