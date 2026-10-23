<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Create users. Quests are created in QuestFixtures. The News and QrCode branches that
        // used to follow were removed with their entities (IP-157): nothing read either table.
        $this->createUsers($manager);

        $manager->flush();
    }

    private function createUsers(ObjectManager $manager): array
    {
        $users = [];

        // Create admin user.
        //
        // ROLE_SUPERADMIN, not ROLE_ADMIN: the latter is in no role_hierarchy entry and in no
        // access_control rule, so it granted nothing and this account could not open /admin
        // despite its name. It was also outside UserRole, which meant the admin's role editor
        // dropped it from the ticks and erased it on the first save.
        $admin = new User();
        $admin->setEmail('admin@monad.sk');
        $admin->setName('Admin User');
        $admin->grantRole(UserRole::SUPERADMIN);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'password123'));
        $admin->setStatus(UserStatus::ACTIVE);
        $manager->persist($admin);
        $users['admin'] = $admin;

        // Create 5 regular users
        for ($i = 1; $i <= 5; $i++) {
            $user = new User();
            $user->setEmail("user{$i}@monad.sk");
            $user->setName("User {$i}");
            $user->setPassword($this->passwordHasher->hashPassword($user, 'password123'));
            $user->setStatus(UserStatus::ACTIVE);
            $manager->persist($user);
            $users["user{$i}"] = $user;
        }

        return $users;
    }
}
