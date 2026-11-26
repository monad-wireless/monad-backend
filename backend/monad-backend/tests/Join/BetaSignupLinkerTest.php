<?php

namespace App\Tests\Join;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaAvailability;
use App\Enum\BetaPlatform;
use App\Enum\BetaSignupStatus;
use App\Enum\UserStatus;
use App\Join\BetaSignupLinker;
use App\Repository\BetaSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The register-time link between an account and a beta signup (IP-157).
 *
 * Against a real PostgreSQL because the match is `LOWER(email)` in the repository, the same
 * comparison the unique index makes, and a double would not exercise it.
 */
class BetaSignupLinkerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BetaSignupLinker $linker;
    private BetaSignupRepository $signups;
    /** The per-test suffix that keeps `users.email` free of collisions; see addr(). */
    private string $tag;
    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->linker = $container->get(BetaSignupLinker::class);
        $this->signups = $container->get(BetaSignupRepository::class);
        $this->tag = uniqid();
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

    /**
     * A fresh address per test run. `users.email` is unique case-SENSITIVELY in the schema, so a
     * run that died before its tear-down would otherwise poison every later run with a duplicate
     * key, and a concurrent lane on the shared test database would do the same.
     */
    private function addr(string $local): string
    {
        return sprintf('%s-%s@example.test', $local, $this->tag);
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setStatus(UserStatus::ACTIVE);
        $this->em->persist($user);
        $this->userIds[] = (string) $user->getId()?->toRfc4122();

        return $user;
    }

    private function signup(string $email, BetaSignupStatus $status): BetaSignup
    {
        $signup = new BetaSignup($email, BetaPlatform::IOS, BetaAvailability::YES, '2026-09-16');
        $this->em->persist($signup);
        if ($status === BetaSignupStatus::INVITED) {
            $signup->markInvited($this->user($this->addr('admin-' . uniqid())));
        } elseif ($status === BetaSignupStatus::DECLINED) {
            $signup->markDeclined();
        } elseif ($status === BetaSignupStatus::WITHDRAWN) {
            $signup->withdraw();
        } elseif ($status === BetaSignupStatus::REGISTERED) {
            $signup->markRegistered($this->user($this->addr('earlier-' . uniqid())));
        }
        $this->em->flush();

        return $signup;
    }

    private function reload(BetaSignup $signup): BetaSignup
    {
        $this->em->clear();
        $row = $this->signups->find($signup->getId());
        self::assertInstanceOf(BetaSignup::class, $row);

        return $row;
    }

    public function testLinksANewSignupAndSetsTheCohort(): void
    {
        $signup = $this->signup(mb_strtoupper($this->addr('ada')), BetaSignupStatus::NEW);
        $user = $this->user($this->addr('ada'));

        $linked = $this->linker->link($user, new \DateTimeImmutable('2026-09-16 12:00:00'));
        $this->em->flush();

        self::assertNotNull($linked);
        self::assertSame('beta', $user->getCohort());
        $row = $this->reload($signup);
        self::assertSame(BetaSignupStatus::REGISTERED, $row->getStatus());
        self::assertSame('2026-09-16 12:00:00', $row->getRegisteredAt()?->format('Y-m-d H:i:s'));
        self::assertSame($user->getId()?->toRfc4122(), $row->getUser()?->getId()?->toRfc4122());
    }

    public function testLinksAnInvitedSignup(): void
    {
        $signup = $this->signup($this->addr('bob'), BetaSignupStatus::INVITED);
        $user = $this->user(mb_strtoupper($this->addr('bob')));

        self::assertNotNull($this->linker->link($user));
        $this->em->flush();

        self::assertSame(BetaSignupStatus::REGISTERED, $this->reload($signup)->getStatus());
        self::assertSame('beta', $user->getCohort());
    }

    /** @return iterable<string, array{BetaSignupStatus}> */
    public static function terminalStatuses(): iterable
    {
        yield 'declined' => [BetaSignupStatus::DECLINED];
        yield 'registered' => [BetaSignupStatus::REGISTERED];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStatuses')]
    public function testLeavesATerminalSignupAlone(BetaSignupStatus $status): void
    {
        $signup = $this->signup($this->addr('carol'), $status);
        $user = $this->user($this->addr('carol'));

        self::assertNull($this->linker->link($user));
        $this->em->flush();

        self::assertSame($status, $this->reload($signup)->getStatus());
        self::assertNull($user->getCohort());
    }

    public function testAWithdrawnSignupNoLongerMatchesItsEmail(): void
    {
        $signup = $this->signup($this->addr('dan'), BetaSignupStatus::WITHDRAWN);
        $user = $this->user($this->addr('dan'));

        self::assertNull($this->linker->link($user));
        self::assertSame(BetaSignupStatus::WITHDRAWN, $this->reload($signup)->getStatus());
    }

    public function testNoSignupNoChange(): void
    {
        $user = $this->user($this->addr('nobody'));

        self::assertNull($this->linker->link($user));
        self::assertNull($user->getCohort());
    }
}
