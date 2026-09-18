<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaAvailability;
use App\Enum\BetaPlatform;
use App\Enum\BetaSignupStatus;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class OnboardingControllerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $admin;
    private BetaSignup $signup;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = (new User())->setEmail('eml-admin@example.test')->setName('Ján Operator')->setPassword('test');
        $this->admin->grantRole(UserRole::SUPERADMIN);
        $this->signup = (new BetaSignup('eml-person@example.test', BetaPlatform::ANDROID, BetaAvailability::YES, 'test'))->setName('Žofia');
        $this->em->persist($this->admin);
        $this->em->persist($this->signup);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM beta_signups WHERE id = :id', ['id' => (string) $this->signup->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => (string) $this->admin->getId()]);
        parent::tearDown();
        $this->em->close();
    }

    public function testDownloadIsAnUncachedMessageAndDoesNotMarkInvited(): void
    {
        $response = $this->call($this->draftUrl(), $this->admin);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('message/rfc822', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('eml-person@example.test', $response->getContent());
        self::assertStringContainsString('eml-admin@example.test', $response->getContent());
        $saved = $this->em->find(BetaSignup::class, $this->signup->getId());
        self::assertSame(BetaSignupStatus::NEW, $saved->getStatus());
        self::assertNull($saved->getInvitedAt());
    }

    public function testAnonymousDownloadRequiresLogin(): void
    {
        $response = $this->call($this->draftUrl());
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/login', $response->headers->get('Location'));
    }

    public function testParticipantCannotDownloadInvitations(): void
    {
        $this->admin->setAssignedRoles([]);
        $this->em->flush();
        self::assertSame(403, $this->call($this->draftUrl(), $this->admin)->getStatusCode());
    }

    public function testDeclinedSignupCannotDownloadAndInvalidIdsReturn404(): void
    {
        $this->signup->markDeclined();
        $this->em->flush();
        self::assertSame(404, $this->call($this->draftUrl(), $this->admin)->getStatusCode());
        self::assertSame(404, $this->call('/admin/people/onboarding/not-a-uuid/invitation.eml', $this->admin)->getStatusCode());
    }

    public function testDeskRendersWithDeferredScriptsAndVisibleNavigation(): void
    {
        $response = $this->call('/admin/people/onboarding', $this->admin);
        self::assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        self::assertStringContainsString('data-datatable', $html);
        self::assertStringContainsString($this->draftUrl(), $html);
        self::assertMatchesRegularExpression('/<script[^>]+admin-onboarding\.js[^>]+defer/', $html);
        self::assertMatchesRegularExpression('/aria-current="page"[^>]*>Participants<\/a>/', $html);
        self::assertStringNotContainsString('row.mailto', $html);
    }

    private function draftUrl(): string
    {
        return '/admin/people/onboarding/' . $this->signup->getId() . '/invitation.eml';
    }

    public function testAccountRegisterRendersWithSearchAndStateFields(): void
    {
        $response = $this->call('/admin/user', $this->admin);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Search all records', $response->getContent());
        self::assertStringContainsString($this->admin->getEmail(), $response->getContent());
    }

    private function call(string $uri, ?User $as = null): Response
    {
        $request = Request::create($uri, 'GET');
        if ($as !== null) {
            $session = static::getContainer()->get('session.factory')->createSession();
            $session->set('_security_admin', serialize(new UsernamePasswordToken($as, 'admin', $as->getRoles())));
            $session->save();
            $request->cookies->set($session->getName(), $session->getId());
        }
        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        $this->em->clear();

        return $response;
    }
}
