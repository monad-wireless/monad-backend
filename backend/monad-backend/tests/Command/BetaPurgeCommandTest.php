<?php

namespace App\Tests\Command;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaAvailability;
use App\Enum\BetaPlatform;
use App\Enum\BetaSignupStatus;
use App\Enum\UserStatus;
use App\Repository\BetaSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:beta:purge against a real PostgreSQL (IP-157).
 *
 * The retention window in the test env is 90 days (.env.test MONAD_BETA_RETENTION_DAYS). Rows
 * are dated relative to now so the test does not age out.
 */
class BetaPurgeCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BetaSignupRepository $signups;
    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->signups = $container->get(BetaSignupRepository::class);
        $this->em->getConnection()->executeStatement('TRUNCATE beta_signups');
    }

    /**
     * The signups go first: `beta_signups.invited_by_id` points at the admin account this test
     * made, so deleting the users before the rows that reference them is a foreign-key violation,
     * not a clean-up. Run on the DBAL connection rather than the EntityManager, which a failed
     * assertion may have closed.
     */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('TRUNCATE beta_signups');
        foreach ($this->userIds as $id) {
            $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $id]);
        }
        parent::tearDown();
        $this->em->close();
    }

    private function user(): User
    {
        $user = (new User())->setEmail(sprintf('purge-%s@example.test', uniqid()))->setPassword('x')->setStatus(UserStatus::ACTIVE);
        $this->em->persist($user);
        $this->userIds[] = (string) $user->getId()?->toRfc4122();

        return $user;
    }

    private function signup(string $email, string $createdAgo): BetaSignup
    {
        $signup = new BetaSignup($email, BetaPlatform::ANDROID, BetaAvailability::REMOTE, '2026-09-16', new \DateTimeImmutable($createdAgo));
        $this->em->persist($signup);

        return $signup;
    }

    /** @return array<string, BetaSignup> */
    private function seed(): array
    {
        $rows = [
            'invited_old' => $this->signup('invited-old@example.test', '-120 days'),
            'invited_recent' => $this->signup('invited-recent@example.test', '-120 days'),
            'new_old' => $this->signup('new-old@example.test', '-100 days'),
            'new_recent' => $this->signup('new-recent@example.test', '-10 days'),
            'declined_old' => $this->signup('declined-old@example.test', '-100 days'),
            'registered_old' => $this->signup('registered-old@example.test', '-200 days'),
        ];
        $admin = $this->user();
        $rows['invited_old']->markInvited($admin, new \DateTimeImmutable('-100 days'));
        $rows['invited_recent']->markInvited($admin, new \DateTimeImmutable('-10 days'));
        $rows['declined_old']->markDeclined();
        $rows['registered_old']->markInvited($admin, new \DateTimeImmutable('-150 days'));
        $rows['registered_old']->markRegistered($this->user(), new \DateTimeImmutable('-140 days'));
        $this->em->flush();

        return $rows;
    }

    private function purge(bool $apply): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:beta:purge'));
        $tester->execute($apply ? ['--apply' => true] : []);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    /** @return array<string, BetaSignup> */
    private function reload(array $rows): array
    {
        $this->em->clear();
        $out = [];
        foreach ($rows as $key => $row) {
            $fresh = $this->signups->find($row->getId());
            self::assertInstanceOf(BetaSignup::class, $fresh);
            $out[$key] = $fresh;
        }

        return $out;
    }

    public function testDryRunListsAndWritesNothing(): void
    {
        $rows = $this->seed();

        $out = $this->purge(false)->getDisplay();

        self::assertStringContainsString('invited-old@example.test', $out);
        self::assertStringContainsString('new-old@example.test', $out);
        self::assertStringContainsString('declined-old@example.test', $out);
        self::assertStringNotContainsString('invited-recent@example.test', $out);
        self::assertStringNotContainsString('new-recent@example.test', $out);
        self::assertStringNotContainsString('registered-old@example.test', $out);
        self::assertStringContainsString('Would scrub', $out);
        self::assertStringContainsString('Dry run', $out);

        foreach ($this->reload($rows) as $key => $row) {
            self::assertSame($rows[$key]->getEmail(), $row->getEmail(), "$key untouched by a dry run");
        }
    }

    public function testApplyScrubsOnlyRowsPastTheWindow(): void
    {
        $rows = $this->seed();

        $out = $this->purge(true)->getDisplay();
        self::assertStringContainsString('Scrubbed', $out);
        self::assertStringNotContainsString('Dry run', $out);

        $fresh = $this->reload($rows);
        foreach (['invited_old', 'new_old', 'declined_old'] as $key) {
            self::assertSame(BetaSignupStatus::WITHDRAWN, $fresh[$key]->getStatus(), "$key is withdrawn");
            self::assertStringNotContainsString('@example.test', $fresh[$key]->getEmail(), "$key email is scrubbed");
            self::assertNull($fresh[$key]->getName());
            self::assertNotNull($fresh[$key]->getWithdrawnAt());
        }
        self::assertSame($rows['invited_old']->getInvitedAt()?->format('U'), $fresh['invited_old']->getInvitedAt()?->format('U'), 'dates stay');

        self::assertSame(BetaSignupStatus::INVITED, $fresh['invited_recent']->getStatus());
        self::assertSame(BetaSignupStatus::NEW, $fresh['new_recent']->getStatus());
        self::assertSame(BetaSignupStatus::REGISTERED, $fresh['registered_old']->getStatus(), 'registered rows are never candidates');
        self::assertSame('registered-old@example.test', $fresh['registered_old']->getEmail());
    }

    public function testNothingPastTheWindowIsSaidSo(): void
    {
        $this->signup('fresh@example.test', '-1 day');
        $this->em->flush();

        self::assertStringContainsString('Nothing past the window', $this->purge(true)->getDisplay());
    }
}
