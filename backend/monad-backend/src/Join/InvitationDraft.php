<?php

declare(strict_types=1);

namespace App\Join;

use App\Entity\User;
use App\Enum\BetaPlatform;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * The invitation a tester receives after the operator pre-created their account: the install
 * link, the sign-in email and the generated password. No transport and no state changes; the
 * operator sends it from their own mail client.
 *
 * The password arrives as an argument because only its hash is stored. A message can therefore
 * be produced only in the response that created or reset the password (AccountProvisioner).
 */
final class InvitationDraft
{
    /** The public TestFlight link (researcher, 2026-11-23). Anyone with it can join the beta. */
    public const TESTFLIGHT_URL = 'https://testflight.apple.com/join/MAYWqH2H';

    /**
     * The rolling Android beta. monad-app's `android-beta.yml` republishes it on every push to
     * feature/refactor, so the address never changes (monad-app ANDROID-BETA.md).
     */
    public const ANDROID_APK_URL = 'https://github.com/monad-wireless/monad-app/releases/download/android-latest/monad-app.apk';

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param BetaPlatform|null $platform from the tester's signup; null (no signup) means iPhone,
     *                                    the platform the TestFlight link serves
     */
    public function create(User $recipient, string $password, User $sender, string $privacyUrl, ?BetaPlatform $platform = null): Email
    {
        $email = $recipient->getEmail();
        if ($email === null || $email === '' || $recipient->isDeleted()) {
            throw new \LogicException('An anonymised account cannot receive an invitation.');
        }
        $platform ??= BetaPlatform::IOS;

        $template = $this->twig->load('join/invitation.txt.twig');
        $context = [
            'name' => $recipient->getName(),
            // iPhone installs through TestFlight, Android from the APK link. An unsure signup
            // gets both install paths.
            'ios' => BetaPlatform::ANDROID !== $platform,
            'android' => BetaPlatform::IOS !== $platform,
            'testflight_url' => self::TESTFLIGHT_URL,
            'android_apk_url' => self::ANDROID_APK_URL,
            'privacy_url' => $privacyUrl,
            'login_email' => $email,
            'password' => $password,
            'sender_name' => $sender->getName() ?: $sender->getEmail(),
            'sender_email' => $sender->getEmail(),
        ];
        $message = (new Email())
            ->from(new Address((string) $sender->getEmail(), $sender->getName() ?? ''))
            ->to(new Address($email, $recipient->getName() ?? ''))
            ->subject(trim($template->renderBlock('subject', $context)))
            ->text(trim($template->renderBlock('body', $context)), 'utf-8');
        // Clients that honour X-Unsent open a draft. Apple Mail offers Message > Send Again.
        $message->getHeaders()->addTextHeader('X-Unsent', '1');

        return $message;
    }
}
