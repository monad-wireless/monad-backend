<?php

declare(strict_types=1);

namespace App\Command;

use App\Lab\Evidence\EvidenceSealService;
use App\Lab\Evidence\SweepReceiptService;
use App\Repository\LabEvidenceManifestRepository;
use App\Repository\LabReferenceReceiptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The bounded operator retry for IP-162 evidence (proposal §3): re-verify every pending seal and
 * re-reconcile every pending receipt. A phone's retry must not be the only way a pending row can
 * recover — the sidecar may have landed after the seal call, or a receipt may have arrived before
 * its recording's manifest.
 *
 * Idempotent: a row that cannot advance stays where it is with its reasons.
 */
#[AsCommand(
    name: 'app:lab-evidence:reconcile',
    description: 'Re-verify pending evidence seals and re-reconcile pending sweep receipts (IP-162).',
)]
final class ReconcileLabEvidenceCommand extends Command
{
    public function __construct(
        private readonly LabEvidenceManifestRepository $manifests,
        private readonly LabReferenceReceiptRepository $receipts,
        private readonly EvidenceSealService $seal,
        private readonly SweepReceiptService $receiptService,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many pending seals', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be re-verified and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(0, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $pendingSeals = $this->manifests->findPending($limit);
        $io->title(sprintf('IP-162 evidence reconcile: %d pending seal(s)', count($pendingSeals)));
        $advanced = 0;
        foreach ($pendingSeals as $row) {
            $before = $row->getState();
            if ($dryRun) {
                $io->writeln(sprintf('  would re-verify %s %s', $row->getRecordingSessionId(), substr($row->getManifestSha256(), 0, 12)));
                continue;
            }
            $after = $this->seal->reverify($row)->row?->getState() ?? $before;
            if ($after !== $before) {
                $advanced++;
            }
            $io->writeln(sprintf('  %s %s: %s -> %s', $row->getRecordingSessionId(), substr($row->getManifestSha256(), 0, 12), $before, $after));
        }

        $pendingReceipts = $this->receipts->findPending();
        $recordings = array_values(array_unique(array_map(static fn ($r) => $r->getRecordingSessionId(), $pendingReceipts)));
        $io->section(sprintf('%d pending receipt(s) across %d recording(s)', count($pendingReceipts), count($recordings)));
        if (!$dryRun) {
            foreach ($recordings as $recording) {
                $this->receiptService->reconcileRecording($recording);
            }
            $this->em->flush();
        }

        $io->success(sprintf('%d seal(s) advanced; receipts re-reconciled for %d recording(s)%s', $advanced, count($recordings), $dryRun ? ' (dry run, nothing written)' : ''));

        return Command::SUCCESS;
    }
}
