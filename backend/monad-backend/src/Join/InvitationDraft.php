<?php

declare(strict_types=1);

namespace App\Join;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaPlatform;
use App\Enum\BetaSignupStatus;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/** A downloadable message, with no transport and no signup state changes. */
final class InvitationDraft
{
    /**
     * The rolling Android beta. monad-app's `android-beta.yml` republishes it on every push to
     * feature/refactor, so the address never changes (monad-app ANDROID-BETA.md).
     */
    public const ANDROID_APK_URL = 'https://github.com/monad-wireless/monad-app/releases/download/android-latest/monad-app.apk';

    public function __construct(private readonly Environment $twig)
    {
    }

    public function create(BetaSignup $signup, User $sender, string $privacyUrl): Email
    {
        if (!in_array($signup->getStatus(), [BetaSignupStatus::NEW, BetaSignupStatus::INVITED], true)) {
            throw new \LogicException('Only new or invited signups can receive an invitation.');
        }

        $template = $this->twig->load('join/invitation.txt.twig');
        $context = [
            'name' => $signup->getName(),
            // iPhone installs through a TestFlight invitation, Android from the APK link. An unsure
            // signup gets both install sections.
            'ios' => BetaPlatform::ANDROID !== $signup->getPlatform(),
            'android' => BetaPlatform::IOS !== $signup->getPlatform(),
            'android_apk_url' => self::ANDROID_APK_URL,
            'privacy_url' => $privacyUrl,
            // Registration activates at once only for this address (BetaSignupLinker::isInvited).
            'recipient_email' => $signup->getEmail(),
            'sender_name' => $sender->getName() ?: $sender->getEmail(),
            'sender_email' => $sender->getEmail(),
        ];
        $message = (new Email())
            ->from(new Address($sender->getEmail(), $sender->getName() ?? ''))
            ->to(new Address($signup->getEmail(), $signup->getName() ?? ''))
            ->subject(trim($template->renderBlock('subject', $context)))
            ->text(trim($template->renderBlock('body', $context)), 'utf-8');
        // Clients that honour X-Unsent open a draft. Apple Mail offers Message > Send Again.
        $message->getHeaders()->addTextHeader('X-Unsent', '1');

        return $message;
    }
}
