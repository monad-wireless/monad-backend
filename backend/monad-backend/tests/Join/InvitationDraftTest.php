<?php

declare(strict_types=1);

namespace App\Tests\Join;

use App\Entity\User;
use App\Enum\BetaPlatform;
use App\Join\AccountProvisioner;
use App\Join\InvitationDraft;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InvitationDraftTest extends TestCase
{
    public function testIphoneMessageCarriesTestFlightLinkAndCredentials(): void
    {
        $recipient = (new User())->setEmail('tester@example.test')->setName('Žofia & Ján');
        $sender = (new User())->setEmail('operator@example.test')->setName('Ľuboš');
        $draft = $this->drafts()->create($recipient, 'abcd-efgh-jkmn', $sender, 'https://example.test/privacy', BetaPlatform::IOS);

        self::assertSame('tester@example.test', $draft->getTo()[0]->getAddress());
        self::assertSame('Žofia & Ján', $draft->getTo()[0]->getName());
        self::assertSame('operator@example.test', $draft->getFrom()[0]->getAddress());
        self::assertSame('Pozvánka na testovanie MonadCount', $draft->getSubject());
        $body = (string) $draft->getTextBody();
        self::assertStringContainsString('Ahoj, Žofia & Ján,', $body);
        self::assertStringNotContainsString('&amp;', $body);
        self::assertStringContainsString(InvitationDraft::TESTFLIGHT_URL, $body);
        self::assertStringContainsString('Prihlásiť sa môžeš cez tvoj e-mail: tester@example.test', $body);
        self::assertStringContainsString('Heslo: abcd-efgh-jkmn', $body);
        self::assertStringContainsString('Change password', $body);
        self::assertStringContainsString("Counting\nZone counting", $body);
        self::assertStringNotContainsString(InvitationDraft::ANDROID_APK_URL, $body);
        self::assertStringContainsString('https://example.test/privacy', $body);
        self::assertStringContainsString('Ľuboš', $body);
        self::assertStringNotContainsString('Dohodneme si termín', $body);
        $wire = $draft->toString();
        self::assertStringContainsString("X-Unsent: 1\r\n", $wire);
        self::assertStringContainsString('charset=utf-8', $wire);
    }

    public function testAndroidSignupGetsTheApkAndNoTestFlight(): void
    {
        $draft = $this->drafts()->create((new User())->setEmail('tester@example.test'), 'abcd-efgh-jkmn', (new User())->setEmail('operator@example.test'), 'https://example.test/privacy', BetaPlatform::ANDROID);
        self::assertStringContainsString('Ahoj,', (string) $draft->getTextBody());
        self::assertStringContainsString(InvitationDraft::ANDROID_APK_URL, (string) $draft->getTextBody());
        self::assertStringNotContainsString('TestFlight', (string) $draft->getTextBody());
    }

    public function testUnsureSignupGetsBothInstallPathsAndNoSignupMeansIphone(): void
    {
        $sender = (new User())->setEmail('operator@example.test');
        $both = (string) $this->drafts()->create((new User())->setEmail('t@example.test'), 'p', $sender, 'https://example.test/privacy', BetaPlatform::UNSURE)->getTextBody();
        self::assertStringContainsString(InvitationDraft::TESTFLIGHT_URL, $both);
        self::assertStringContainsString(InvitationDraft::ANDROID_APK_URL, $both);
        $none = (string) $this->drafts()->create((new User())->setEmail('t@example.test'), 'p', $sender, 'https://example.test/privacy')->getTextBody();
        self::assertStringContainsString(InvitationDraft::TESTFLIGHT_URL, $none);
        self::assertStringNotContainsString(InvitationDraft::ANDROID_APK_URL, $none);
    }

    public function testAnonymisedAccountCannotReceiveAMessage(): void
    {
        $user = (new User())->setEmail('tester@example.test')->setPassword('x');
        $user->softDelete();
        $this->expectException(\LogicException::class);
        $this->drafts()->create($user, 'p', (new User())->setEmail('operator@example.test'), 'https://example.test/privacy');
    }

    public function testGeneratedPasswordIsReadableAndInsideThePolicy(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; ++$i) {
            $p = AccountProvisioner::generatePassword();
            self::assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{4}-[a-hjkmnp-z2-9]{4}-[a-hjkmnp-z2-9]{4}$/', $p);
            $seen[$p] = true;
        }
        self::assertCount(50, $seen);
    }

    private function drafts(): InvitationDraft
    {
        return new InvitationDraft(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['autoescape' => 'name']));
    }
}
