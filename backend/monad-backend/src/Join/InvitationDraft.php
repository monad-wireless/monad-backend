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
            'platform' => match ($signup->getPlatform()) {
                BetaPlatform::IOS => 'iPhone',
                BetaPlatform::ANDROID => 'Android',
                BetaPlatform::UNSURE => 'iPhone alebo Android',
            },
            'privacy_url' => $privacyUrl,
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
