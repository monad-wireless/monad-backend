<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\Notification;
use App\Entity\PushToken;
use App\Entity\User;
use App\Enum\NotificationAudience;
use App\Enum\NotificationType;
use App\Enum\PushPlatform;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Shared scaffolding for the IP-157 notification tests against the real PostgreSQL.
 *
 * The users this suite creates carry a `notif-test-` email prefix and are deleted in tearDown
 * by that prefix, because the test database is shared with other lanes' suites and truncating
 * `users` would pull the rug from under them. `notifications`, `notification_deliveries` and
 * `push_tokens` are this feature's own tables and are truncated per test; other suites already
 * truncate `push_tokens` themselves (it references handsets).
 */
abstract class NotificationDbTestCase extends KernelTestCase
{
    protected const EMAIL_PREFIX = 'notif-test-';

    protected EntityManagerInterface $em;

    /** @var list<string> ids of the users this test created; softDelete() rewrites the email, so the prefix alone would miss them */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->createdUserIds = [];
        $this->em->getConnection()->executeStatement('TRUNCATE notification_deliveries, notifications, push_tokens');
        $this->em->getConnection()->executeStatement("DELETE FROM users WHERE email LIKE 'notif-test-%'");
    }

    protected function tearDown(): void
    {
        // notifications first: created_by_id has no cascade.
        $this->em->getConnection()->executeStatement('TRUNCATE notification_deliveries, notifications, push_tokens');
        $this->em->getConnection()->executeStatement("DELETE FROM users WHERE email LIKE 'notif-test-%'");
        foreach ($this->createdUserIds as $id) {
            $this->em->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $id]);
        }
        parent::tearDown();
        $this->em->close();
    }

    protected function makeUser(
        string $tag,
        UserStatus $status = UserStatus::ACTIVE,
        ?string $cohort = null,
        bool $admin = false,
        bool $notifyGeneral = true,
        bool $notifyCallouts = false,
    ): User {
        $user = (new User())
            ->setEmail(sprintf('%s%s-%s@example.test', self::EMAIL_PREFIX, $tag, bin2hex(random_bytes(4))))
            ->setName($tag)
            ->setPassword('x')
            ->setStatus($status)
            ->setCohort($cohort)
            ->setNotifyGeneral($notifyGeneral)
            ->setNotifyCallouts($notifyCallouts);
        if ($admin) {
            $user->grantRole(UserRole::SUPERADMIN);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->createdUserIds[] = (string) $user->getId()?->toRfc4122();

        return $user;
    }

    protected function makeToken(User $user, string $token, bool $revoked = false, PushPlatform $platform = PushPlatform::ANDROID): PushToken
    {
        $row = new PushToken($user, $platform, $token);
        if ($revoked) {
            $row->revoke();
        }
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    protected function makeNotification(
        User $author,
        NotificationType $type = NotificationType::GENERAL,
        NotificationAudience $audience = NotificationAudience::BETA,
        string $title = 'Hello',
        string $body = 'A body.',
    ): Notification {
        $n = new Notification($type, $title, $body, $audience, $author);
        $this->em->persist($n);
        $this->em->flush();

        return $n;
    }

    /** @param list<User> $users @return list<string> */
    protected static function ids(array $users): array
    {
        return array_map(static fn (User $u): string => (string) $u->getId()?->toRfc4122(), $users);
    }
}
