<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaAvailability;
use App\Enum\BetaPlatform;
use App\Enum\BetaSignupStatus;
use App\Enum\UserStatus;
use App\Repository\BetaSignupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Invitation-only registration and the password change, over the real kernel, the login
 * firewall and PostgreSQL.
 *
 * Every row carries the `auth-test-` email prefix and is deleted by it, signups first because
 * `beta_signups` references `users`. The test database is shared with other lanes' suites, so
 * nothing is truncated.
 */
final class AuthAccountTest extends KernelTestCase
{
    private const PREFIX = 'auth-test-';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
        $this->em->close();
    }

    private function purge(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM beta_signups WHERE email LIKE 'auth-test-%'");
        $connection->executeStatement("DELETE FROM users WHERE email LIKE 'auth-test-%'");
    }

    private static function addr(string $local): string
    {
        return sprintf('%s%s-%s@example.test', self::PREFIX, $local, bin2hex(random_bytes(4)));
    }

    /** @param array<string, mixed> $json */
    private function call(string $method, string $uri, array $json, ?string $jwt = null): Response
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($jwt !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
        }
        $request = Request::create($uri, $method, [], [], [], $server, json_encode($json, JSON_THROW_ON_ERROR));

        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        $this->em->clear();

        return $response;
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function user(string $email, string $password, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = (new User())->setEmail($email)->setStatus($status);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function signup(string $email, BetaSignupStatus $status): void
    {
        $signup = new BetaSignup($email, BetaPlatform::IOS, BetaAvailability::YES, '2026-09-16');
        $this->em->persist($signup);
        if ($status === BetaSignupStatus::INVITED) {
            $signup->markInvited($this->user(self::addr('admin'), 'admin-password'));
        }
        $this->em->flush();
    }

    private function reloadUser(string $email): User
    {
        $this->em->clear();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    // ── registration ─────────────────────────────────────────────────────────────────────────

    public function testAnInvitedEmailIsActiveAtOnceAndGetsAToken(): void
    {
        $email = self::addr('invited');
        $this->signup($email, BetaSignupStatus::INVITED);

        $response = $this->call('POST', '/api/auth/register', ['email' => $email, 'password' => 'long-enough']);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $body = self::body($response);
        self::assertSame('active', $body['status']);
        self::assertIsString($body['token']);
        self::assertSame(UserStatus::ACTIVE, $this->reloadUser($email)->getStatus());
        $signup = static::getContainer()->get(BetaSignupRepository::class)->findByEmail($email);
        self::assertSame(BetaSignupStatus::REGISTERED, $signup?->getStatus());
    }

    public function testAnUninvitedEmailIsPendingWithoutAToken(): void
    {
        $email = self::addr('stranger');

        $response = $this->call('POST', '/api/auth/register', ['email' => $email, 'password' => 'long-enough']);

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $body = self::body($response);
        self::assertSame('pending', $body['status']);
        self::assertNull($body['token']);
        self::assertSame(UserStatus::PENDING, $this->reloadUser($email)->getStatus());
    }

    public function testASignupThatWasNeverInvitedStaysNewAndTheAccountPends(): void
    {
        $email = self::addr('asked');
        $this->signup($email, BetaSignupStatus::NEW);

        $response = $this->call('POST', '/api/auth/register', ['email' => $email, 'password' => 'long-enough']);

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $signup = static::getContainer()->get(BetaSignupRepository::class)->findByEmail($email);
        self::assertSame(BetaSignupStatus::NEW, $signup?->getStatus());
    }

    public function testRegisterRefusesAShortPassword(): void
    {
        $response = $this->call('POST', '/api/auth/register', ['email' => self::addr('short'), 'password' => 'seven77']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('VALIDATION_105', self::body($response)['code']);
    }

    // ── login ────────────────────────────────────────────────────────────────────────────────

    public function testAPendingAccountCannotSignIn(): void
    {
        $email = self::addr('pending');
        $this->user($email, 'long-enough', UserStatus::PENDING);

        $response = $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'long-enough']);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('AUTH_009', self::body($response)['code']);
    }

    public function testAnApprovedAccountSignsIn(): void
    {
        $email = self::addr('approved');
        $user = $this->user($email, 'long-enough', UserStatus::PENDING);
        $user->setStatus(UserStatus::ACTIVE);
        $this->em->flush();

        $response = $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'long-enough']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertIsString(self::body($response)['token']);
    }

    // ── password change ──────────────────────────────────────────────────────────────────────

    public function testChangePasswordReplacesTheCredential(): void
    {
        $email = self::addr('changer');
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user($email, 'old-password'));

        $response = $this->call('POST', '/api/auth/password', ['current_password' => 'old-password', 'new_password' => 'new-password'], $jwt);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'new-password'])->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'old-password'])->getStatusCode());
    }

    public function testChangePasswordRefusesAWrongCurrentPasswordWithoutA401(): void
    {
        $email = self::addr('guesser');
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user($email, 'old-password'));

        $response = $this->call('POST', '/api/auth/password', ['current_password' => 'not-it', 'new_password' => 'new-password'], $jwt);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('AUTH_010', self::body($response)['code']);
        self::assertSame(Response::HTTP_OK, $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'old-password'])->getStatusCode());
    }

    public function testChangePasswordRefusesAShortNewPassword(): void
    {
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user(self::addr('shorty'), 'old-password'));

        $response = $this->call('POST', '/api/auth/password', ['current_password' => 'old-password', 'new_password' => 'short'], $jwt);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('VALIDATION_105', self::body($response)['code']);
    }

    public function testChangePasswordRequiresAToken(): void
    {
        $response = $this->call('POST', '/api/auth/password', ['current_password' => 'x', 'new_password' => 'new-password']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
