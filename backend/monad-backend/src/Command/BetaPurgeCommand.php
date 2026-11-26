<?php

namespace App\Command;

use App\Entity\BetaSignup;
use App\Repository\BetaSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention for beta signups (IP-157).
 *
 * The consent text on /join promises that a signup which is not followed by an account is
 * deleted MONAD_BETA_RETENTION_DAYS days after the invitation, or the same number of days after
 * signing up if no invitation is sent. This command is that promise. A row past its window is
 * withdrawn in place through BetaSignup::withdraw(): email, name and notes are scrubbed, the
 * dates stay so the funnel still counts it. `registered` rows are never touched (that account is
 * anonymised through User::softDelete()), and `withdrawn` rows are already scrubbed.
 *
 * DRY RUN BY DEFAULT. Without --apply it lists what would go and writes nothing.
 */
#[AsCommand(
    name: 'app:beta:purge',
    description: 'Scrub beta signups that never registered within the retention window (dry run unless --apply).',
)]
class BetaPurgeCommand extends Command
{
    public function __construct(
        private readonly BetaSignupRepository $signups,
        private readonly EntityManagerInterface $em,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Scrub the rows listed; without it nothing is written');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $now = new \DateTimeImmutable();
        $cutoff = $now->modify(sprintf('-%d days', $this->retentionDays));

        $io->title('Beta signup retention');
        $io->text(sprintf('Window %d days (MONAD_BETA_RETENTION_DAYS); anything before %s goes.', $this->retentionDays, $cutoff->format('Y-m-d H:i:s T')));

        $rows = $this->signups->findPurgeCandidates($cutoff);
        if ($rows === []) {
            $io->success('Nothing past the window.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Status', 'Signed up', 'Invited', 'Email', 'Name'],
            array_map(static fn (BetaSignup $s) => [
                $s->getStatus()->value,
                $s->getCreatedAt()->format('Y-m-d'),
                $s->getInvitedAt()?->format('Y-m-d') ?? '—',
                $s->getEmail(),
                $s->getName() ?? '—',
            ], $rows),
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->getStatus()->value] = ($counts[$row->getStatus()->value] ?? 0) + 1;
            if ($apply) {
                $row->withdraw($now);
            }
        }
        if ($apply) {
            $this->em->flush();
        }

        $lines = [];
        foreach ($counts as $status => $n) {
            $lines[] = [$status => $n];
        }
        $lines[] = [($apply ? 'Scrubbed' : 'Would scrub') => count($rows)];
        $io->definitionList(...$lines);
        if (!$apply) {
            $io->note('Dry run: nothing was written. Re-run with --apply to scrub these rows.');
        }

        return Command::SUCCESS;
    }
}
