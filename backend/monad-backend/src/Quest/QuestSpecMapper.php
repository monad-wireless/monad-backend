<?php

declare(strict_types=1);

namespace App\Quest;

use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The quest as a document: the `lab_quest_write` argument shape, both ways (IP-157).
 *
 * `toSpec()` is the projection `lab_quest_read` returns, kept to the keys `lab_quest_write`
 * ACCEPTS so an export re-imports without editing: `route_pool` (a list of routes) rather than
 * `route_policy` (the stored wrapper), and no `created_by`. `LabTools` is not edited by this
 * lane, so its `questRead()` keeps its own copy of the projection; the two must stay
 * key-compatible, which `tests/Quest/QuestSpecMapperTest.php` pins against the published
 * payloads.
 *
 * `buildSteps()` is the step normalisation `questWrite()` applies (order defaults to the index,
 * config to `[]`) followed by the entity constraint on `QuestStep::$config`, so the builder, the
 * import and the MCP tool refuse the same malformed config with the same sentence.
 */
final class QuestSpecMapper
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * Where a quest stands relative to now, as one word. The same rule as
     * `LabTools::describeWindow()`, which is private to the tool and not edited by this lane.
     */
    public static function status(Quest $quest, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $from = $quest->getAvailableFrom();
        $to = $quest->getAvailableTo();

        if ($from !== null && $from > $now) {
            return 'scheduled';
        }
        if ($to !== null && $to < $now) {
            return 'hidden';
        }

        return 'live';
    }

    /** @return array<string, mixed> the `lab_quest_write` argument shape */
    public function toSpec(Quest $quest): array
    {
        $steps = $quest->getSteps()->toArray();
        usort($steps, static fn (QuestStep $a, QuestStep $b): int => $a->getOrder() <=> $b->getOrder());

        $spec = [
            'name' => $quest->getName(),
            'description' => $quest->getDescription(),
            'available_from' => $quest->getAvailableFrom()?->format(\DateTimeInterface::ATOM),
            'available_to' => $quest->getAvailableTo()?->format(\DateTimeInterface::ATOM),
            'points' => $quest->getPoints(),
            'estimated_duration' => $quest->getEstimatedDuration(),
            'audience' => $quest->getAudience(),
            'steps' => array_map(static fn (QuestStep $s): array => [
                'order' => $s->getOrder(),
                'name' => $s->getName(),
                'type' => $s->getType()?->value,
                'config' => $s->getConfig(),
            ], $steps),
        ];

        $routes = self::routesOf($quest->getRoutePolicy());
        if ($routes !== []) {
            $spec['route_pool'] = $routes;
        }

        return $spec;
    }

    /**
     * The routes of a pool policy as a list of key lists; empty for fixed, null or malformed.
     *
     * @param array<string, mixed>|null $policy
     * @return list<list<string>>
     */
    public static function routesOf(?array $policy): array
    {
        if ($policy === null || ($policy['mode'] ?? 'fixed') !== 'pool' || !is_array($policy['routes'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($policy['routes'] as $route) {
            if (!is_array($route) || $route === []) {
                continue;
            }
            $out[] = array_values(array_map(static fn ($k): string => (string) $k, $route));
        }

        return $out;
    }

    /**
     * The step normalisation `lab_quest_write` applies before validation: order defaults to the
     * index, config to an empty object, name and type pass through as given.
     *
     * @param list<mixed> $steps
     * @return list<array{order: int, name: string, type: string, config: array<string, mixed>}>
     */
    public static function normaliseSteps(array $steps): array
    {
        $out = [];
        foreach (array_values($steps) as $i => $data) {
            $data = is_array($data) ? $data : [];
            $out[] = [
                'order' => is_int($data['order'] ?? null) ? $data['order'] : $i,
                'name' => (string) ($data['name'] ?? ''),
                'type' => (string) ($data['type'] ?? ''),
                'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
            ];
        }

        return $out;
    }

    /**
     * Build the step entities and validate every one through the entity constraint.
     *
     * Nothing is attached to a quest here: the caller decides whether the stored rows are
     * replaced, and it must not do so while any violation stands.
     *
     * @param list<mixed> $steps
     * @return array{steps: list<QuestStep>, violations: array<int, list<string>>} violations keyed by step index
     */
    public function buildSteps(array $steps): array
    {
        $built = [];
        $violations = [];

        foreach (self::normaliseSteps($steps) as $i => $data) {
            $type = QuestStepType::tryFrom($data['type']);
            if ($type === null) {
                $violations[$i][] = sprintf('Unknown step type "%s".', $data['type']);
                continue;
            }
            if (trim($data['name']) === '') {
                $violations[$i][] = 'Step name is required.';
            }

            $step = (new QuestStep())
                ->setName($data['name'])
                ->setType($type)
                ->setOrder($data['order'])
                ->setConfig($data['config']);

            // Only the config property: `quest` is not set yet, and its NotNull is not the question.
            foreach ($this->validator->validateProperty($step, 'config') as $violation) {
                $violations[$i][] = (string) $violation->getMessage();
            }

            $built[] = $step;
        }

        return ['steps' => $built, 'violations' => $violations];
    }

    /**
     * Write the header fields of a spec onto a quest, the way `lab_quest_write` reads them.
     * Omitted `audience` and `route_pool` are left unchanged, as there.
     *
     * @param array<string, mixed> $spec
     * @return list<string> errors; the quest is untouched when non-empty
     */
    public function applyHeader(Quest $quest, array $spec): array
    {
        $errors = [];
        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'name is required.';
        }
        $description = (string) ($spec['description'] ?? '');
        if (trim($description) === '') {
            $errors[] = 'description is required.';
        }

        $from = null;
        $to = null;
        try {
            $from = new \DateTime((string) ($spec['available_from'] ?? ''));
            if (($spec['available_to'] ?? null) !== null && (string) $spec['available_to'] !== '') {
                $to = new \DateTime((string) $spec['available_to']);
            }
        } catch (\Exception $e) {
            $errors[] = 'Unparseable date: ' . $e->getMessage();
        }

        $points = $spec['points'] ?? 0.0;
        if (!is_numeric($points) || (float) $points < 0) {
            $errors[] = 'points must be zero or positive.';
        }
        $duration = $spec['estimated_duration'] ?? null;
        if ($duration !== null && (!is_int($duration) || $duration <= 0)) {
            $errors[] = 'estimated_duration must be a positive number of minutes.';
        }
        if (array_key_exists('route_pool', $spec) && $spec['route_pool'] !== null && !is_array($spec['route_pool'])) {
            $errors[] = 'route_pool must be a list of routes.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $quest->setName($name);
        $quest->setDescription($description);
        $quest->setAvailableFrom($from);
        $quest->setAvailableTo($to);
        $quest->setPoints((float) $points);
        $quest->setEstimatedDuration($duration);

        if (($spec['audience'] ?? null) !== null) {
            $quest->setAudience((string) $spec['audience']);
        }
        if (isset($spec['route_pool']) && is_array($spec['route_pool'])) {
            $routes = array_values(array_filter(
                $spec['route_pool'],
                static fn ($r): bool => is_array($r) && $r !== [],
            ));
            $quest->setRoutePolicy($routes === [] ? null : ['mode' => 'pool', 'routes' => $routes]);
        }

        return [];
    }

    /**
     * A route may only name keys the quest's probe steps accept, or the walker is sent to a
     * point no step can complete.
     *
     * @param list<list<string>> $routes
     * @param list<array{type: string, config: array<string, mixed>}> $steps normalised
     * @return list<string>
     */
    public static function routeViolations(array $routes, array $steps): array
    {
        $known = [];
        foreach ($steps as $step) {
            if (($step['type'] ?? '') !== QuestStepType::PROBE->value) {
                continue;
            }
            foreach ((array) ($step['config']['targets'] ?? []) as $target) {
                if (is_array($target) && isset($target['value'])) {
                    $known[QuestPreflight::targetKey((string) $target['value'])] = true;
                }
            }
        }

        $out = [];
        foreach ($routes as $r => $route) {
            foreach ($route as $key) {
                if (!isset($known[QuestPreflight::targetKey($key)])) {
                    $out[] = sprintf('Route %d names "%s", which no probe step in this quest accepts.', $r + 1, $key);
                }
            }
        }

        return $out;
    }
}
