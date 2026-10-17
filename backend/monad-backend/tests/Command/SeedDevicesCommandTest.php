<?php

namespace App\Tests\Command;

use App\Command\SeedDevicesCommand;
use PHPUnit\Framework\TestCase;

/**
 * Reading the label registry.
 *
 * This parser is the join between a sticker and a database row, and the owner's
 * roster decision (2026-08-14) makes the `devices` table authoritative for
 * `/d/<slug>`: an unseeded slug 404s. So a slug this parser fails to read is a
 * sticker that scans to nothing, and the failure is silent — the command reports
 * success having seeded a subset.
 *
 * It broke exactly that way once. The label tooling became kind-parameterised on
 * 2026-08-14 and `[[nodes]] host = "monadNN"` became `[[items]] slug = "monadNN"`,
 * which the old single regex could not see at all; the six unflashed inventory
 * slots also collapsed into one numbered series, which a literal-only reader
 * would have skipped even after the key was fixed.
 */
class SeedDevicesCommandTest extends TestCase
{
    /** @return string[] */
    private function parse(string $toml): array
    {
        $rc = new \ReflectionClass(SeedDevicesCommand::class);
        $method = $rc->getMethod('parseSlugs');

        return $method->invoke($rc->newInstanceWithoutConstructor(), $toml);
    }

    public function testReadsLiteralSlugs(): void
    {
        $toml = <<<'TOML'
            kind = "fleet"

            [[items]]
            slug = "monad01"
            rows = { "CSI radio" = "24:eb:16:e3:68:54" }

            [[items]]
            slug = "monad02"
            TOML;

        self::assertSame(['monad01', 'monad02'], $this->parse($toml));
    }

    public function testExpandsANumberedSeries(): void
    {
        // The unflashed inventory slots. Defined by size so they cannot acquire
        // a skipped or repeated number by hand.
        $toml = <<<'TOML'
            [[items]]
            slug = "monad{n:02d}"
            count = 6
            start = 7
            TOML;

        self::assertSame(
            ['monad07', 'monad08', 'monad09', 'monad10', 'monad11', 'monad12'],
            $this->parse($toml),
        );
    }

    public function testAttributesCountToItsOwnSeries(): void
    {
        // Two series in one file: a parser that scans the whole document for
        // `count` rather than the table it belongs to reads the wrong size.
        $toml = <<<'TOML'
            [[items]]
            slug = "alpha{n}"
            count = 2

            [[items]]
            slug = "beta{n}"
            count = 3
            start = 5
            TOML;

        self::assertSame(['alpha1', 'alpha2', 'beta5', 'beta6', 'beta7'], $this->parse($toml));
    }

    public function testIgnoresSlugsMentionedInComments(): void
    {
        // The registry carries long explanatory comments; one of them documents
        // the schema using the very syntax this parser matches.
        $toml = <<<'TOML'
            # Promote a slot once its addresses are known:
            #   slug = "monad99"

            [[items]]
            slug = "monad01"
            TOML;

        self::assertSame(['monad01'], $this->parse($toml));
    }

    public function testWrongSchemaYieldsNothingRatherThanASubset(): void
    {
        // The pre-2026-08-14 shape. Returning [] makes the command fail loudly;
        // returning a partial list would seed a subset and report success.
        $toml = <<<'TOML'
            [[nodes]]
            host = "monad01"
            TOML;

        self::assertSame([], $this->parse($toml));
    }

    public function testSeriesWithoutACountIsSkipped(): void
    {
        // A template that names nothing. The label tooling rejects it at load,
        // so inventing a size here would put a slug in the database that no
        // sticker carries.
        $toml = <<<'TOML'
            [[items]]
            slug = "monad{n:02d}"
            TOML;

        self::assertSame([], $this->parse($toml));
    }

    public function testTheRealRegistryReadsTwelveNodes(): void
    {
        $path = __DIR__ . '/../../../../../../infra/labels/fleet.toml';
        if (!is_file($path)) {
            self::markTestSkipped('monad-knowledge checkout not alongside this repo');
        }

        $slugs = $this->parse((string) file_get_contents($path));

        self::assertSame(
            ['monad01', 'monad02', 'monad03', 'monad04', 'monad05', 'monad06',
             'monad07', 'monad08', 'monad09', 'monad10', 'monad11', 'monad12'],
            $slugs,
        );
    }
}
