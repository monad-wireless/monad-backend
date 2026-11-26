<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\LabSession;
use App\Lab\Evidence\SealOutcome;
use App\Lab\Evidence\UploadedManifestSealer;
use App\Repository\LabSessionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seal every complete recording whose phone uploaded an evidence manifest that nobody sealed
 * (IP-162). Until 2026-10-20 the app uploaded `evidence-manifest.json` and no client posted it to
 * the seal route, so each Counting receipt stayed `manifest_not_sealed`; the upload path now seals
 * on arrival, and this is the one-shot backfill for what came before.
 *
 * The seal is attributed to the recording's own user (the account that uploaded it), so the
 * ownership rules are the ones the participant's own call would meet. Idempotent: a sealed
 * recording has a seal attempt and is no longer a candidate.
 */
#[AsCommand(
    name: 'app:lab-evidence:seal-uploaded',
    description: 'Seal complete recordings from their uploaded evidence-manifest.json (IP-162 backfill).',
)]
final class SealUploadedEvidenceCommand extends Command
{
    public function __construct(
        private readonly LabSessionRepository $sessions,
        private readonly UploadedManifestSealer $sealer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many recordings', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the recordings that would be sealed and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(0, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $ids = $this->sessions->findUnsealedWithArtefact(UploadedManifestSealer::MANIFEST, $limit);
        $io->title(sprintf('IP-162 seal backfill: %d unsealed recording(s) with an uploaded manifest', count($ids)));

        $tally = [];
        foreach ($ids as $id) {
            if ($dryRun) {
                $io->writeln(sprintf('  would seal %s', $id));
                continue;
            }
            $session = $this->sessions->find($id);
            $outcome = $session instanceof LabSession ? $this->sealer->sealFromBucket($session, $session->getUser()) : null;
            $status = $outcome?->status ?? 'manifest_absent';
            $tally[$status] = ($tally[$status] ?? 0) + 1;
            $io->writeln(sprintf('  %s: %s%s', $id, $status, $this->reasons($outcome)));
        }

        if ($dryRun) {
            $io->success(sprintf('%d recording(s) would be sealed (dry run, nothing written)', count($ids)));

            return Command::SUCCESS;
        }
        ksort($tally);
        $io->success(sprintf(
            '%d recording(s) processed: %s',
            count($ids),
            $tally === [] ? 'none' : implode(', ', array_map(static fn (string $s, int $n) => "$s $n", array_keys($tally), $tally)),
        ));

        return Command::SUCCESS;
    }

    private function reasons(?SealOutcome $outcome): string
    {
        if ($outcome === null || $outcome->reasons === []) {
            return '';
        }

        return ' (' . implode(', ', $outcome->reasons) . ')';
    }
}
