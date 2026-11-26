<?php

namespace App\Command;

use App\Entity\Device;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed the `devices` table from the printed-label registry (IP-128).
 *
 * The registry (`infra/labels/fleet.toml` in monad-knowledge) is the file the
 * physical stickers were printed from, which makes it the right *bootstrap*
 * source: a slug in the database that nobody can be holding is useless, and a
 * sticker with no row behind it is a dead scan.
 *
 * It is a bootstrap, not a sync. After seeding, the database is authoritative —
 * label, location and public blurb are edited in `/admin`, and this command will
 * not overwrite them. Re-running only adds nodes that are missing, so it is safe
 * on a populated database and safe to run twice.
 *
 * The TOML is parsed by hand rather than by pulling in a parser dependency: the
 * needed subset is the `slug` of each `[[items]]` table, and this is a one-shot
 * operator command rather than a hot path.
 *
 * The registry schema changed on 2026-08-14 when the label tooling became
 * kind-parameterised: `[[nodes]] host = "monadNN"` became `[[items]] slug =
 * "monadNN"`, and the unflashed inventory slots collapsed from six near-identical
 * blocks into one numbered series (`slug = "monad{n:02d}", count = 6, start = 7`).
 * A parser that only understands literal slugs silently seeds six of twelve.
 * Since a slug that is not seeded resolves to a 404 on `/d/<slug>`, an under-read
 * registry is a set of stickers that scan to nothing.
 */
#[AsCommand(
    name: 'app:devices:seed',
    description: 'Create device rows from the printed-label registry (idempotent).',
)]
class SeedDevicesCommand extends Command
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'registry',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to fleet.toml (the file the labels were printed from)',
            )
            ->addOption(
                'slug',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Seed only these slugs (repeatable). Default: every node in the registry.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = $input->getOption('registry');
        if (!is_string($path) || '' === $path) {
            $io->error('Pass --registry=/path/to/fleet.toml (the monad-knowledge label registry).');

            return Command::INVALID;
        }
        if (!is_file($path) || !is_readable($path)) {
            $io->error(sprintf('Registry not readable: %s', $path));

            return Command::INVALID;
        }

        $slugs = $this->parseSlugs((string) file_get_contents($path));
        if ([] === $slugs) {
            $io->error('No [[items]] entries found — is that really the label registry?');

            return Command::FAILURE;
        }

        $only = $input->getOption('slug');
        if (is_array($only) && [] !== $only) {
            $slugs = array_values(array_intersect($slugs, $only));
            $missing = array_diff($only, $slugs);
            if ([] !== $missing) {
                $io->warning(sprintf('Not in the registry, skipped: %s', implode(', ', $missing)));
            }
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $created = [];
        $existing = [];

        foreach ($slugs as $slug) {
            if (null !== $this->devices->findBySlug($slug)) {
                // Already there. Never overwrite: label / location / blurb are
                // operator content owned by /admin from this point on.
                $existing[] = $slug;
                continue;
            }
            $created[] = $slug;
            if ($dryRun) {
                continue;
            }

            $device = (new Device())
                ->setSlug($slug)
                // A placeholder an operator will improve in /admin. Naming it
                // after the slug beats an empty string in every listing.
                ->setLabel($slug);
            $this->entityManager->persist($device);
        }

        if (!$dryRun && [] !== $created) {
            $this->entityManager->flush();
        }

        $io->definitionList(
            ['Registry' => $path],
            ['In registry' => count($slugs)],
            ['Already present' => count($existing)],
            [$dryRun ? 'Would create' : 'Created' => count($created)],
        );
        if ([] !== $created) {
            $io->listing($created);
        }
        if ($dryRun) {
            $io->note('Dry run — nothing was written.');
        } elseif ([] !== $created) {
            $io->success('Seeded. Set label / location / public blurb in /admin.');
        } else {
            $io->success('Nothing to do — every registry node already has a row.');
        }

        return Command::SUCCESS;
    }

    /**
     * Pull every slug out of the registry's `[[items]]` tables.
     *
     * Two forms exist and both must be read:
     *
     *   [[items]]                      [[items]]
     *   slug = "monad01"               slug = "monad{n:02d}"
     *                                  count = 6
     *                                  start = 7
     *
     * The second is a numbered series — the set is defined by its size, so that
     * the fleet's unflashed inventory slots cannot acquire a skipped or repeated
     * number by hand. Reading only the first form is not a partial success: it
     * seeds six of twelve and leaves the other six scanning to a 404.
     *
     * Anchored to the start of a line so a `slug` mentioned inside one of the
     * file's long explanatory comments cannot be mistaken for an entry.
     *
     * @return string[] unique, in file order
     */
    private function parseSlugs(string $toml): array
    {
        // Split on the table header so `count`/`start` are attributed to the
        // series they belong to rather than to whichever one appeared last.
        $tables = preg_split('/^\s*\[\[items\]\]\s*$/m', $toml) ?: [];
        $slugs = [];

        foreach ($tables as $table) {
            if (1 !== preg_match('/^\s*slug\s*=\s*"([^"]+)"/m', $table, $m)) {
                continue;
            }
            $slug = $m[1];

            if (!str_contains($slug, '{n')) {
                $slugs[] = $slug;
                continue;
            }

            // A series without a count is a template that names nothing; the
            // label tooling rejects it at load, so skipping is the honest read.
            if (1 !== preg_match('/^\s*count\s*=\s*(\d+)/m', $table, $c)) {
                continue;
            }
            $count = (int) $c[1];
            $start = 1 === preg_match('/^\s*start\s*=\s*(\d+)/m', $table, $s) ? (int) $s[1] : 1;

            for ($n = $start; $n < $start + $count; $n++) {
                // Mirrors the tooling's `"{n:02d}".format(n=...)`; the registry
                // uses no other numbering spec.
                $slugs[] = str_replace(
                    ['{n:02d}', '{n}'],
                    [sprintf('%02d', $n), (string) $n],
                    $slug,
                );
            }
        }

        return array_values(array_unique($slugs));
    }
}
