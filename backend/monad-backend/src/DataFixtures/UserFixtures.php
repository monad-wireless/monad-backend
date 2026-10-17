<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public const SUPERADMIN_REFERENCE = 'superadmin-user';

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Idempotent: seeding an already-populated database (a deployment, or an --append run
        // that pulls this in as a dependency) must not collide on the unique e-mail.
        $existing = $manager->getRepository(User::class)->findOneBy(['email' => 'admin@fiit.stuba.sk']);
        if ($existing instanceof User) {
            $this->addReference(self::SUPERADMIN_REFERENCE, $existing);

            return;
        }

        $superadmin = new User();
        $superadmin->setEmail('admin@fiit.stuba.sk');
        $superadmin->setName('FIIT Admin');
        $superadmin->setPassword(
            $this->passwordHasher->hashPassword($superadmin, 'admin123')
        );
        $superadmin->grantRole(UserRole::SUPERADMIN);

        $manager->persist($superadmin);
        $manager->flush();

        $this->addReference(self::SUPERADMIN_REFERENCE, $superadmin);
    }
}
