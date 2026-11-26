<?php

namespace App\Command;

use App\Service\LabSessionRegister;
use App\Service\S3Service;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Build the recording-session register from what is already on S3 (IP-149).
 *
 * The register is written by the upload path from 2026-09-04 on. Every session
 * before that exists only as a prefix under `datasets/monad-app-sessions/`, and
 * this command reads each one back: the listing gives the artefacts, the sidecar
 * (when the session completed) gives everything else.
 *
 * ONE-SHOT AND IDEMPOTENT, by owner decision (IP-149 Q3). It runs once after the
 * deploy and can run again at any time: the upserts it goes through are the same
 * ones the live path uses, so a row the live path already wrote is left with the
 * same artefacts and the same completion instant. It doubles as the reconciler for
 * a session whose S3 write succeeded and whose row write did not.
 *
 * Artefact entries built from the listing carry `transport: listed` — the listing
 * cannot know whether a file arrived whole or in parts — and `stored_at` is the
 * object's S3 LastModified. A live-path entry for the same file wins nothing over
 * this one; they describe the same object.
 */
#[AsCommand(
    name: 'app:lab-sessions:backfill',
    description: 'Create recording-session rows for every session already on S3 (idempotent).',
)]
class BackfillLabSessionsCommand extends Command
{
    private const SIDECAR = 'metadata.json';

    /** The controller's inspection cap, mirrored: a sidecar over it is not read. */
    private const MAX_SIDECAR_BYTES = 1024 * 1024;

    public function __construct(
        private readonly S3Service $s3,
        private readonly LabSessionRegister $register,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('participant', null, InputOption::VALUE_REQUIRED, 'Only this participant pseudonym (one S3 prefix segment)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many sessions', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be written and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $participant = $input->getOption('participant');
        $limit = $input->getOption('limit') !== null ? max(0, (int) $input->getOption('limit')) : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Recording-session register backfill');
        $sessions = $this->s3->listSessionObjects(is_string($participant) ? $participant : null);
        $io->text(sprintf('%d session prefix(es) under %s', count($sessions), S3Service::SESSIONS_PREFIX));

        $written = 0;
        $completed = 0;
        $incomplete = 0;
        $skipped = 0;
        foreach ($sessions as $prefix => $files) {
            if ($limit !== null && $written >= $limit) {
                break;
            }
            [$participantId, $sessionId] = explode('/', $prefix, 2);
            $hasSidecar = array_key_exists(self::SIDECAR, $files);

            if ($dryRun) {
                $io->text(sprintf('  %s  %d artefact(s)%s', $prefix, count($files), $hasSidecar ? '' : '  (no sidecar — incomplete)'));
                ++$written;
                $hasSidecar ? ++$completed : ++$incomplete;
                continue;
            }

            foreach ($files as $filename => $meta) {
                $storedAt = isset($meta['last_modified']) && is_string($meta['last_modified'])
                    ? new \DateTimeImmutable($meta['last_modified'])
                    : null;
                $this->register->artefactStored(
                    $sessionId,
                    $participantId,
                    null,
                    $filename,
                    (int) $meta['bytes'],
                    self::contentTypeFor($filename),
                    'listed',
                    $storedAt,
                );
            }

            if ($hasSidecar) {
                $raw = $this->s3->getSessionObject($participantId, $sessionId, self::SIDECAR, self::MAX_SIDECAR_BYTES);
                if ($raw === null) {
                    $io->warning(sprintf('%s: sidecar unreadable or over %d bytes; row left incomplete', $prefix, self::MAX_SIDECAR_BYTES));
                    ++$skipped;
                } else {
                    // The completion instant of a backfilled row is the sidecar's own upload time,
                    // not "now": a register that says every old session completed on the day of the
                    // backfill would be wrong in the one column an operator sorts by.
                    $sidecarAt = isset($files[self::SIDECAR]['last_modified']) && is_string($files[self::SIDECAR]['last_modified'])
                        ? new \DateTimeImmutable($files[self::SIDECAR]['last_modified'])
                        : null;
                    if ($this->register->sessionCompleted($sessionId, $participantId, null, $raw, $sidecarAt)) {
                        ++$completed;
                    } else {
                        ++$skipped;
                    }
                }
            } else {
                ++$incomplete;
            }
            ++$written;
            if ($output->isVerbose()) {
                $io->text(sprintf('  %s  %d artefact(s)%s', $prefix, count($files), $hasSidecar ? '' : '  (no sidecar)'));
            }
        }

        $io->definitionList(
            ['Sessions ' . ($dryRun ? 'listed' : 'written') => $written],
            ['Completed (sidecar present)' => $completed],
            ['Incomplete (no sidecar)' => $incomplete],
            ['Sidecar unreadable' => $skipped],
        );
        if ($dryRun) {
            $io->note('Dry run: nothing was written.');
        }

        return Command::SUCCESS;
    }

    /** The listing carries no content type; infer the one the app would have sent. */
    private static function contentTypeFor(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'tsv' => 'text/tab-separated-values',
            'json' => 'application/json',
            'csv' => 'text/csv',
            'txt', 'log' => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
