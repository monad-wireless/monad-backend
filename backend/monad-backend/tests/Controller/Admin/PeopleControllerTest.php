<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaAvailability;
use App\Enum\BetaPlatform;
use App\Enum\BetaSignupStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Join\InvitationDraft;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The people desk (2026-11-23): one list, one-click account creation from a signup, the
 * one-time invitation, password reset and access. Against a real Postgres, because "one account
 * per address" rests on the lower(email) comparison the database performs.
 */
final class PeopleControllerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $admin;
    private BetaSignup $signup;
    /** @var list<string> */
    private array $emails = ['people-admin@example.test', 'people-person@example.test'];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
        $this->admin = (new User())->setEmail('people-admin@example.test')->setName('Ján Operator')->setPassword('test');
        $this->admin->grantRole(UserRole::SUPERADMIN);
        $this->signup = (new BetaSignup('People-Person@example.test', BetaPlatform::IOS, BetaAvailability::YES, 'test'))->setName('Žofia');
        $this->em->persist($this->admin);
        $this->em->persist($this->signup);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
        $this->em->close();
    }

    public function testCreateAccountMakesAnActiveBetaAccountAndShowsThePasswordOnce(): void
    {
        $response = $this->call($this->createUrl(), $this->admin, 'POST', ['_token' => 'tok'], ['people' => 'tok']);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $html = (string) $response->getContent();
        self::assertStringContainsString(InvitationDraft::TESTFLIGHT_URL, $html);
        // The signup has capitals; the account and the invitation use the lower-cased address,
        // because login matches exactly and the app only trims.
        self::assertStringContainsString('Prihlásiť sa môžeš cez tvoj e-mail: people-person@example.test', $html);
        self::assertMatchesRegularExpression('/Heslo: ([a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4})/', $html);
        preg_match('/Heslo: ([a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4})/', $html, $m);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'people-person@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::ACTIVE, $user->getStatus());
        self::assertSame('beta', $user->getCohort());
        self::assertSame('Žofia', $user->getName());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $m[1]));
        self::assertStringNotContainsString($m[1], (string) $user->getPassword());

        $signup = $this->em->find(BetaSignup::class, $this->signup->getId());
        self::assertSame(BetaSignupStatus::REGISTERED, $signup->getStatus());
        self::assertNotNull($signup->getInvitedAt());
        self::assertSame((string) $user->getId(), (string) $signup->getUser()?->getId());
    }

    public function testASecondClickCreatesNoSecondAccount(): void
    {
        $this->call($this->createUrl(), $this->admin, 'POST', ['_token' => 'tok'], ['people' => 'tok']);
        $again = $this->call($this->createUrl(), $this->admin, 'POST', ['_token' => 'tok'], ['people' => 'tok']);
        self::assertSame(302, $again->getStatusCode());
        self::assertStringContainsString('/admin/people/', (string) $again->headers->get('Location'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM users WHERE LOWER(email) = 'people-person@example.test'"));
    }

    public function testAPendingAppRegistrationIsReusedAndActivated(): void
    {
        $pending = (new User())->setEmail('people-person@example.test')->setPassword('x')->setStatus(UserStatus::PENDING);
        $this->em->persist($pending);
        $this->em->flush();

        self::assertSame(200, $this->call($this->createUrl(), $this->admin, 'POST', ['_token' => 'tok'], ['people' => 'tok'])->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM users WHERE LOWER(email) = 'people-person@example.test'"));
        $reloaded = $this->em->find(User::class, $pending->getId());
        self::assertSame(UserStatus::ACTIVE, $reloaded->getStatus());
    }

    public function testStaleTokenIsRefused(): void
    {
        self::assertSame(403, $this->call($this->createUrl(), $this->admin, 'POST', ['_token' => 'wrong'], ['people' => 'tok'])->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM users WHERE LOWER(email) = 'people-person@example.test'"));
    }

    public function testParticipantCannotOpenTheDesk(): void
    {
        $this->admin->setAssignedRoles([]);
        $this->em->flush();
        self::assertSame(403, $this->call('/admin/people', $this->admin)->getStatusCode());
    }

    public function testListShowsTheSignupWithACreateButtonAndTheNavEntry(): void
    {
        $response = $this->call('/admin/people', $this->admin);
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('data-datatable', $html);
        self::assertStringContainsString($this->createUrl(), $html);
        self::assertStringContainsString('People-Person@example.test', $html);
        self::assertMatchesRegularExpression('/aria-current="page"[^>]*>People<\/a>/', $html);
        self::assertMatchesRegularExpression('/<script[^>]+admin-onboarding\.js[^>]+defer/', $html);
    }

    public function testOldDeskAddressRedirectsToTheSignupsThatNeedAnAccount(): void
    {
        $response = $this->call('/admin/people/onboarding', $this->admin);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/admin/people?stage=awaiting', (string) $response->headers->get('Location'));
    }

    public function testPersonPageAndPasswordReset(): void
    {
        $this->call($this->createUrl(), $this->admin, 'POST', ['_token' => 'tok'], ['people' => 'tok']);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'people-person@example.test']);
        $old = $user->getPassword();
        $page = $this->call('/admin/people/' . $user->getId(), $this->admin);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('id="runs"', (string) $page->getContent());
        self::assertStringContainsString('Sign-ins are not recorded', (string) $page->getContent());

        $reset = $this->call('/admin/people/' . $user->getId() . '/password', $this->admin, 'POST', ['_token' => 'tok'], ['people' => 'tok']);
        self::assertSame(200, $reset->getStatusCode());
        self::assertStringContainsString(InvitationDraft::TESTFLIGHT_URL, (string) $reset->getContent());
        self::assertNotSame($old, $this->em->find(User::class, $user->getId())->getPassword());
    }

    public function testAccessChangeHoldsAndApproves(): void
    {
        $person = (new User())->setEmail('people-person@example.test')->setPassword('x')->setStatus(UserStatus::PENDING);
        $this->em->persist($person);
        $this->em->flush();
        $url = '/admin/people/' . $person->getId() . '/access';

        self::assertSame(302, $this->call($url, $this->admin, 'POST', ['_token' => 'tok', 'status' => 'active'], ['people' => 'tok'])->getStatusCode());
        self::assertSame(UserStatus::ACTIVE, $this->em->find(User::class, $person->getId())->getStatus());
        $this->call($url, $this->admin, 'POST', ['_token' => 'tok', 'status' => 'banned'], ['people' => 'tok']);
        self::assertSame(UserStatus::ACTIVE, $this->em->find(User::class, $person->getId())->getStatus());
    }

    public function testRawAccountRegisterStillRenders(): void
    {
        $response = $this->call('/admin/user', $this->admin);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Search all records', (string) $response->getContent());
        self::assertStringContainsString($this->admin->getEmail(), (string) $response->getContent());
    }

    private function createUrl(): string
    {
        return '/admin/people/signups/' . $this->signup->getId() . '/account';
    }

    /** @param array<string, string> $post @param array<string, string> $csrf */
    private function call(string $uri, ?User $as, string $method = 'GET', array $post = [], array $csrf = []): Response
    {
        $request = Request::create($uri, $method, $post);
        if ($as !== null) {
            $session = static::getContainer()->get('session.factory')->createSession();
            $session->set('_security_admin', serialize(new UsernamePasswordToken($as, 'admin', $as->getRoles())));
            foreach ($csrf as $id => $value) {
                $session->set('_csrf/' . $id, $value);
            }
            $session->save();
            $request->cookies->set($session->getName(), $session->getId());
        }
        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        $this->em->clear();

        return $response;
    }

    private function cleanUp(): void
    {
        $c = $this->em->getConnection();
        $c->executeStatement("DELETE FROM beta_signups WHERE LOWER(email) IN ('people-person@example.test')");
        $c->executeStatement('DELETE FROM users WHERE LOWER(email) IN (:e)', ['e' => $this->emails], ['e' => \Doctrine\DBAL\ArrayParameterType::STRING]);
    }
}
