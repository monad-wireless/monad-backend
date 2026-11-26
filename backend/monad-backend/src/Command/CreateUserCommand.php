<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Create a user, or promote an existing one, from the container.
 *
 * `/api/auth/register` is the participant's door and deliberately mints nothing but ROLE_USER —
 * an endpoint that could grant ROLE_SUPERADMIN would be a privilege-escalation surface open to
 * the internet. So the first administrator has to come from somewhere else, and that somewhere
 * is here rather than a hand-written INSERT: the password must be hashed with the application's
 * own hasher, and a row typed straight into psql is exactly how an account that cannot log in
 * gets created.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create a user (or promote an existing one) with an explicit role',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $users,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address — this is the login identifier')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password; prompted for (hidden) when omitted')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'Grant ROLE_SUPERADMIN')
            ->addOption('promote', null, InputOption::VALUE_NONE, 'Allow updating an existing account instead of failing')
            ->setHelp(<<<'HELP'
                Create a participant:

                    php bin/console app:user:create tester@example.com --name "Test Participant"

                Create the first administrator:

                    php bin/console app:user:create you@stuba.sk --admin

                Promote an account that already exists (leaves its password alone):

                    php bin/console app:user:create you@stuba.sk --admin --promote

                On the deployed host the command runs inside the API container:

                    docker exec -it monad_api php bin/console app:user:create you@stuba.sk --admin
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $wantsAdmin = (bool) $input->getOption('admin');
        $promote = (bool) $input->getOption('promote');

        $user = $this->users->findOneBy(['email' => $email]);

        // Refusing by default rather than upserting: "the command ran fine" must not be the way
        // an operator discovers they have just reset a live account's password.
        if ($user !== null && !$promote) {
            $io->error(sprintf('%s already exists. Re-run with --promote to modify it.', $email));

            return Command::FAILURE;
        }

        $isNew = $user === null;
        if ($isNew) {
            $user = new User();
            $user->setEmail($email);
            $user->setStatus(UserStatus::ACTIVE);
        }

        if ($input->getOption('name') !== null) {
            $user->setName($input->getOption('name'));
        }

        // A password is mandatory for a new account and optional for a promotion, so that
        // granting a role does not force a credential change on someone already using the app.
        $password = $input->getOption('password');
        if ($password === null && $isNew) {
            $question = (new Question('Password: '))->setHidden(true)->setHiddenFallback(false);
            $password = $io->askQuestion($question);
        }

        if ($password !== null && $password !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }

        if ($wantsAdmin) {
            $user->setRoles([UserRole::SUPERADMIN->value]);
        } elseif ($isNew) {
            // getRoles() adds ROLE_USER unconditionally, so an empty array is the correct
            // storage for an ordinary participant — writing ROLE_USER in would duplicate it.
            $user->setRoles([]);
        }

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error(sprintf('%s: %s', $error->getPropertyPath(), $error->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s %s with roles: %s',
            $isNew ? 'Created' : 'Updated',
            $email,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
