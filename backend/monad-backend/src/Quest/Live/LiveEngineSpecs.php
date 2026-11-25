<?php

declare(strict_types=1);

namespace App\Quest\Live;

use App\Quest\Live\Questions\QuestionsSpec;
use App\Quest\Live\RadioHunt\RadioHuntSpec;

/** Every engine a quest may name. A new mechanic adds one line here and one tagged {@see LiveEngine}. */
final class LiveEngineSpecs
{
    /** @return array<string, LiveEngineSpec> keyed by engine key */
    public static function all(): array
    {
        $out = [];
        foreach ([new RadioHuntSpec(), new QuestionsSpec()] as $spec) {
            $out[$spec->key()] = $spec;
        }

        return $out;
    }

    public static function find(mixed $key): ?LiveEngineSpec
    {
        return is_string($key) ? (self::all()[$key] ?? null) : null;
    }

    /**
     * @param array<string, mixed> $config a live step's config
     * @return list<string>
     */
    public static function capabilitiesOf(array $config): array
    {
        $spec = self::find($config['engine'] ?? null);

        return array_values(array_unique([LiveProtocol::CAPABILITY, ...($spec?->requiredCapabilities() ?? [])]));
    }
}
