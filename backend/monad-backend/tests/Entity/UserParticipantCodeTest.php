<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UserParticipantCodeTest extends TestCase
{
    public function testItIsStableAndReadableAsAStorageKey(): void
    {
        $user = new User();

        self::assertSame($user->getParticipantCode(), $user->getParticipantCode());
        self::assertMatchesRegularExpression('/^p-[0-9a-f]{16}$/', $user->getParticipantCode());
    }

    public function testTheDerivationIsFrozen(): void
    {
        // The code is part of the storage path of every recording. A changed derivation splits one
        // person into two.
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))
            ->setValue($user, Uuid::fromString('00000000-0000-4000-8000-000000000001'));

        self::assertSame('p-7e35c49673d22aa8', $user->getParticipantCode());
    }

    public function testTwoAccountsNeverShareOneAndItHoldsNoPartOfTheAccountId(): void
    {
        $a = new User();
        $b = new User();

        self::assertNotSame($a->getParticipantCode(), $b->getParticipantCode());
        self::assertStringNotContainsString(substr($a->getId()->toRfc4122(), 0, 8), $a->getParticipantCode());
    }

    public function testItDoesNotDependOnTheEmailOrTheName(): void
    {
        $user = new User();
        $before = $user->getParticipantCode();
        $user->setEmail('somebody@example.org');

        self::assertSame($before, $user->getParticipantCode());
    }
}
