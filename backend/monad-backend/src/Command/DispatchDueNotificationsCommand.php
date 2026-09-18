<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\NotificationDispatcher;
use App\Repository\NotificationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Send every scheduled notification whose instant has passed (IP-157).
 *
 * Run every minute by the worker's scheduler (src/Schedule.php, `messenger:consume
 * scheduler_default async`), so a scheduled send needs no cron on the host. Idempotent: the
 * query only returns `sent_at IS NULL` rows and the dispatcher refuses a second send, so two
 * overlapping runs cannot double-deliver. Safe by hand, too:
 *
 *   docker exec -it monad_api php bin/console app:notifications:dispatch-due --dry-run
 */
#[AsCommand(
    name: 'app:notifications:dispatch-due',
    description: 'Send every notification scheduled for an instant that has passed (idempotent).',
)]
final class DispatchDueNotificationsCommand extends Command
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what is due and send nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $due = $this->notifications->findDue($now);

        if ($due === []) {
            $io->writeln(sprintf('<info>Nothing due at %s.</info>', $now->format(\DateTimeInterface::ATOM)), OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $rows = [];
        foreach ($due as $notification) {
            $line = [
                $notification->getId()->toRfc4122(),
                $notification->getType()->value,
                $notification->getAudience()->value,
                $notification->getScheduledFor()?->format(\DateTimeInterface::ATOM) ?? '—',
                $notification->getTitle(),
            ];
            if ($dryRun) {
                $line[] = 'would send';
            } else {
                $result = $this->dispatcher->send($notification, $now);
                $line[] = sprintf('%s: inbox %d, queued %d, skipped %d', $result->outcome, $result->inbox, $result->queued, $result->skipped);
            }
            $rows[] = $line;
        }

        $io->table(['id', 'type', 'audience', 'scheduled for', 'title', 'outcome'], $rows);
        if ($dryRun) {
            $io->note('Dry run: nothing was sent.');
        }

        return Command::SUCCESS;
    }
}
