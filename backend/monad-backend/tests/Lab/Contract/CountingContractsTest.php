<?php

declare(strict_types=1);

namespace App\Tests\Lab\Contract;

use App\Enum\QuestStepType;
use App\Lab\Contract\CanonicalJson;
use App\Lab\Contract\CountingContracts;
use App\Quest\Schema\StepSchemaRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The IP-162 conformance fixtures, byte for byte.
 *
 * `tests/fixtures/ip162/` is a verbatim copy of
 * `monad-knowledge/monad_knowledge/lab/contracts/fixtures/`. The Python package there is the
 * reference reader; this backend must reproduce its canonical bytes and digests, accept every
 * valid observe config and manifest, and refuse every invalid one for the reason the fixture
 * names. A fixture that changes there fails here until the copy is refreshed — three
 * repositories, one meaning.
 */
final class CountingContractsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../fixtures/ip162';

    /** @return array<string, mixed> */
    private static function load(string $relative): array
    {
        $json = json_decode((string) file_get_contents(self::ROOT . '/' . $relative), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /** @return list<string> */
    private static function files(string $sub): array
    {
        $found = glob(self::ROOT . '/' . $sub . '/*.json') ?: [];
        sort($found);

        return $found;
    }

    public function testTheCopiedFixturesMatchTheirManifest(): void
    {
        $lines = array_filter(array_map('trim', file(self::ROOT . '/MANIFEST.sha256') ?: []));
        self::assertNotEmpty($lines);
        $manifest = [];
        foreach ($lines as $line) {
            [$digest, $path] = explode('  ', $line, 2);
            $manifest[$path] = $digest;
        }
        $present = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = ltrim(str_replace(self::ROOT, '', $file->getPathname()), '/');
            if ($relative === 'MANIFEST.sha256') {
                continue;
            }
            $present[$relative] = hash_file('sha256', $file->getPathname());
        }
        $drift = [];
        foreach (array_unique(array_merge(array_keys($manifest), array_keys($present))) as $path) {
            if (($manifest[$path] ?? null) !== ($present[$path] ?? null)) {
                $drift[] = $path;
            }
        }
        self::assertSame([], $drift, 'fixture copy disagrees with MANIFEST.sha256; refresh the copy rather than editing it here');
    }

    public function testCanonicalVectorsReproduce(): void
    {
        // Objects preserved: PHP's associative decode turns `{}` into `[]`, and the vectors pin
        // the two apart. The seal hashes raw bytes the same way (CanonicalJson::sha256OfJson).
        $doc = json_decode((string) file_get_contents(self::ROOT . '/canonical/vectors.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($doc->vectors as $vector) {
            self::assertSame($vector->canonical, CanonicalJson::encode($vector->input), $vector->name);
            self::assertSame($vector->sha256, CanonicalJson::sha256($vector->input), $vector->name);
        }
        foreach ($doc->rejected as $rejected) {
            try {
                CanonicalJson::encode($rejected->input);
                self::fail('accepted a forbidden input: ' . $rejected->name);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRawBytesAndTheirAssociativeDecodeHashAlikeWhenNoEmptyObjectIsInvolved(): void
    {
        $raw = (string) file_get_contents(self::ROOT . '/manifest/valid/sealed.json');
        $assoc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['manifest'];
        $objects = json_decode($raw, false, 512, JSON_THROW_ON_ERROR)->manifest;
        self::assertSame(CanonicalJson::sha256($assoc), CanonicalJson::sha256($objects));
    }

    public function testAnEmptyObjectStaysAnObjectAndAnEmptyListStaysAList(): void
    {
        // PHP decodes both `{}` and `[]` to an empty array; the vectors pin them apart by shape.
        // A decoded document loses the distinction, so this backend's rule is: an empty array
        // canonicalises as `[]`, and an object that must stay an object is passed as stdClass.
        self::assertSame('[]', CanonicalJson::encode([]));
        self::assertSame('{}', CanonicalJson::encode(new \stdClass()));
    }

    public function testTheValidObserveConfigPassesTheSchemaAndItsDigestIsSelfConsistent(): void
    {
        $config = self::load('observe/valid/room-sweep.json')['config'];
        self::assertSame([], CountingContracts::observeConfigProblems($config));
        self::assertSame($config['protocol_sha256'], CanonicalJson::selfDigest($config, 'protocol_sha256'));
        // Through the registry too: the admin form, lab_quest_write and the API refuse the same config.
        self::assertSame([], (new StepSchemaRegistry())->for(QuestStepType::OBSERVE)->validate($config));
    }

    public function testEveryInvalidObserveConfigIsRefusedForTheReasonTheFixtureNames(): void
    {
        $registry = new StepSchemaRegistry();
        foreach (self::files('observe/invalid') as $file) {
            $doc = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $problems = CountingContracts::observeConfigProblems($doc['config']);
            self::assertNotSame([], $problems, basename($file));
            $expected = $doc['expect_problem'];
            self::assertTrue(
                (bool) array_filter($problems, static fn (string $p) => str_contains($p, $expected)),
                sprintf('%s: expected a problem naming %s, got %s', basename($file), $expected, json_encode($problems)),
            );
            self::assertNotSame([], $registry->for(QuestStepType::OBSERVE)->validate($doc['config']), basename($file) . ' through the registry');
        }
    }

    public function testTheLegacyPartialViewContractIsUnchanged(): void
    {
        $schema = (new StepSchemaRegistry())->for(QuestStepType::OBSERVE);
        self::assertSame([], $schema->validate(['prompt' => 'How many people can you see?', 'min_readings' => 5]));
        self::assertNotSame([], $schema->validate(['prompt' => 'How many people can you see?']), 'legacy still needs min_readings');
        self::assertNotSame([], $schema->validate(['schema' => 'monad-quest/observe/v9', 'prompt' => 'x']), 'an unknown schema is refused, not read as legacy');
    }

    public function testTheValidManifestPassesAndNamesEveryRequiredArtefact(): void
    {
        $manifest = self::load('manifest/valid/sealed.json')['manifest'];
        self::assertSame([], CountingContracts::evidenceManifestProblems($manifest));
        self::assertSame([], CountingContracts::missingSweepArtifacts($manifest));
        // The reference fixture cites this manifest's digest.
        $reference = self::load('reference/valid/stable-room.json')['reference'];
        self::assertSame($reference['manifest_sha256'], CanonicalJson::sha256($manifest));
    }

    public function testInvalidManifestsAreRefused(): void
    {
        foreach (self::files('manifest/invalid') as $file) {
            $doc = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $problems = CountingContracts::evidenceManifestProblems($doc['manifest']);
            self::assertNotSame([], $problems, basename($file));
            if (isset($doc['expect_missing_artifacts'])) {
                self::assertSame($doc['expect_missing_artifacts'], CountingContracts::missingSweepArtifacts($doc['manifest']), basename($file));
            }
            if (isset($doc['expect_problem'])) {
                self::assertTrue(
                    (bool) array_filter($problems, static fn (string $p) => str_contains($p, $doc['expect_problem'])),
                    sprintf('%s: %s', basename($file), json_encode($problems)),
                );
            }
        }
    }

    public function testTheStudyFixtureIsFrozenOverItsOwnCanonicalBody(): void
    {
        $study = self::load('study/valid/exp-f2-fixture.json')['study'];
        self::assertSame($study['frozen_sha256'], CanonicalJson::selfDigest($study, 'frozen_sha256'));
    }

    public function testASweepSummaryIsAPointerNotACountStream(): void
    {
        $summary = [
            'schema' => 'monad-lab/sweep-summary/v1',
            'recording_session_id' => '0f000000-0000-4000-8000-00000000000a',
            'sweep_id' => '5e000000-0000-4000-8000-000000000001',
            'step_completion_id' => '0c000000-0000-4000-8000-00000000000c',
            'protocol_id' => 'fixture-room-sweep',
            'protocol_sha256' => 'b33e25d256344f01b2991c38afda5dc1592ba801a6cb6e17fac120f3ebc1e24a',
            'room_id' => 'fixture-room-a',
            'coverage_version' => 'c1',
            'phase' => 'finalised',
            'final_event_id' => 'e0000000-0000-4000-8000-000000000005',
            'count' => 6,
            'coverage' => 'complete',
            'occupancy_stability' => 'stable',
            'events_accepted' => 5,
            'corrections' => 0,
        ];
        self::assertSame([], CountingContracts::sweepSummaryProblems($summary));
        self::assertNotSame([], CountingContracts::sweepSummaryProblems(array_replace($summary, ['count' => true])), 'a boolean is not a count');
        self::assertNotSame([], CountingContracts::sweepSummaryProblems(array_replace($summary, ['count' => -1])));
        self::assertNotSame([], CountingContracts::sweepSummaryProblems(array_replace($summary, ['final_event_id' => null])), 'a finalised sweep names its final event');
        self::assertNotSame([], CountingContracts::sweepSummaryProblems(array_replace($summary, ['phase' => 'done'])));
    }
}
