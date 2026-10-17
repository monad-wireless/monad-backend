<?php

declare(strict_types=1);

namespace App\Tests\Join;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaAvailability;
use App\Enum\BetaPlatform;
use App\Enum\BetaSignupStatus;
use App\Join\InvitationDraft;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InvitationDraftTest extends TestCase
{
    public function testPersonalizedSlovakMessageUsesTheOperatorAndPreservesSignupState(): void
    {
        $signup = (new BetaSignup('tester@example.test', BetaPlatform::IOS, BetaAvailability::YES, 'test'))
            ->setName('Žofia & Ján');
        $sender = (new User())->setEmail('operator@example.test')->setName('Ľuboš');
        $draft = $this->drafts()->create($signup, $sender, 'https://example.test/privacy');

        self::assertSame('tester@example.test', $draft->getTo()[0]->getAddress());
        self::assertSame('Žofia & Ján', $draft->getTo()[0]->getName());
        self::assertSame('operator@example.test', $draft->getFrom()[0]->getAddress());
        self::assertSame('Pozvánka na testovanie MonadCount', $draft->getSubject());
        self::assertStringContainsString('Ahoj, Žofia & Ján,', $draft->getTextBody());
        self::assertStringNotContainsString('&amp;', $draft->getTextBody());
        self::assertStringContainsString('pre iPhone', $draft->getTextBody());
        self::assertStringContainsString('https://example.test/privacy', $draft->getTextBody());
        self::assertStringContainsString('Ľuboš', $draft->getTextBody());
        $wire = $draft->toString();
        self::assertStringContainsString("X-Unsent: 1\r\n", $wire);
        self::assertStringContainsString('charset=utf-8', $wire);
        self::assertStringContainsString('Žofia & Ján', quoted_printable_decode(explode("\r\n\r\n", $wire, 2)[1]));
        self::assertSame(BetaSignupStatus::NEW, $signup->getStatus());
        self::assertNull($signup->getInvitedAt());
    }

    public function testInvitedSignupCanDownloadAgainWithoutChangingTheInvitationDate(): void
    {
        $signup = new BetaSignup('tester@example.test', BetaPlatform::ANDROID, BetaAvailability::REMOTE, 'test');
        $sender = (new User())->setEmail('operator@example.test');
        $signup->markInvited($sender);
        $date = $signup->getInvitedAt();
        $draft = $this->drafts()->create($signup, $sender, 'https://example.test/privacy');
        self::assertStringContainsString('Ahoj,', $draft->getTextBody());
        self::assertStringContainsString('pre Android', $draft->getTextBody());
        self::assertSame($date, $signup->getInvitedAt());
    }

    public function testWithdrawnSignupCannotProduceARecipientMessage(): void
    {
        $signup = new BetaSignup('tester@example.test', BetaPlatform::UNSURE, BetaAvailability::YES, 'test');
        $signup->withdraw();
        $this->expectException(\LogicException::class);
        $this->drafts()->create($signup, (new User())->setEmail('operator@example.test'), 'https://example.test/privacy');
    }

    private function drafts(): InvitationDraft
    {
        return new InvitationDraft(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['autoescape' => 'name']));
    }
}
