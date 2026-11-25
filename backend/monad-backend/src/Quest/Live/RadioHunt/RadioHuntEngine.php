<?php

declare(strict_types=1);

namespace App\Quest\Live\RadioHunt;

use App\Quest\Live\Guidance\Guide;
use App\Quest\Live\Guidance\Hint;
use App\Quest\Live\Guidance\NoGuide;
use App\Quest\Live\Guidance\PatternGuide;
use App\Quest\Live\Guidance\PositionGuide;
use App\Quest\Live\Guidance\RadioModel;
use App\Quest\Live\LiveContext;
use App\Quest\Live\LiveEngine;
use App\Quest\Live\LiveEvent;
use App\Quest\Live\LiveView;
use App\Quest\Live\Signal\SignalSource;
use App\Quest\Live\TargetAllocator;
use App\Quest\QuestPreflight;
use App\Repository\LabPlacementRepository;
use Random\Randomizer;

/**
 * The radio hunt: find printed cards with the signal as the only guide.
 *
 *     crew -> seek -> hold -> ask -> seek -> … -> done
 *
 * The player never sees a map or the name of the card. The zone the radio can point at holds more
 * than one card, so a scan of the wrong one is part of the game, and it is data: a scan is a known
 * position whichever card it was.
 *
 * Everything that was shown is logged (`hint`), with the estimate behind it, so a guidance trial
 * can be read afterwards. A missing signal is said as such and is never drawn as cold.
 */
final class RadioHuntEngine implements LiveEngine
{
    private const TREND_SECONDS = 6.0;
    private const HINT_LOG_SECONDS = 2.0;
    private const MESSAGE_SECONDS = 6.0;

    /** @var \WeakMap<LiveContext, array<string, array{0: float, 1: float}>> positions read once per sync */
    private \WeakMap $positions;

    public function __construct(
        private readonly SignalSource $signals,
        private readonly TargetAllocator $allocator,
        private readonly LabPlacementRepository $placements,
        private ?Randomizer $random = null,
    ) {
        $this->random ??= new Randomizer();
        $this->positions = new \WeakMap();
    }

    public function key(): string
    {
        return RadioHuntSpec::KEY;
    }

    public function open(LiveContext $context): array
    {
        $options = self::options($context);
        $state = [
            'phase' => 'crew', 'crew' => null, 'round' => 0, 'rounds' => [],
            'target' => null, 'arm' => null, 'assigned_at' => null, 'wrong' => 0,
            'in_zone' => false, 'history' => [], 'hint' => null, 'hint_logged_at' => 0.0,
            'message' => null, 'message_at' => 0.0,
        ];
        if ($this->targets($context) === []) {
            $context->log('broken', ['reason' => 'no target of this step has a position on the floor', 'floor' => $options['floor'] ?? null]);
            $state['phase'] = 'broken';
        } elseif ($options['crew_question'] === null) {
            $state = $this->nextTarget($state, $context);
        }

        return $state;
    }

    public function handle(array $state, LiveEvent $event, LiveContext $context): array
    {
        return match (true) {
            $state['phase'] === 'crew' && $event->kind === 'answer' && $event->widget() === 'crew' => $this->crew($state, $event, $context),
            $state['phase'] === 'seek' && $event->kind === 'scan' => $this->scan($state, $event, $context),
            $state['phase'] === 'seek' && $event->kind === 'tap' && $event->widget() === $this->id('skip', $state) => $this->skip($state, $context),
            $state['phase'] === 'hold' && $event->kind === 'hold_done' && $event->widget() === $this->id('hold', $state) => $this->held($state, $event, $context),
            $state['phase'] === 'ask' && $event->kind === 'answer' && $event->widget() === $this->id('count', $state) => $this->counted($state, $event, $context),
            default => $state, // a retry or a tap that arrived after its screen was gone
        };
    }

    public function advance(array $state, LiveContext $context): array
    {
        $options = self::options($context);
        if (!in_array($state['phase'], ['seek', 'hold', 'ask'], true)) {
            return $state;
        }
        $this->allocator->renew((string) $options['pool'], $context->runId, $context->now);
        if ($state['message'] !== null && $context->now - $state['message_at'] > self::MESSAGE_SECONDS) {
            $state['message'] = null;
        }

        return $state['phase'] === 'seek' ? $this->guide($state, $context) : $state;
    }

    public function view(array $state, LiveContext $context): LiveView
    {
        $options = self::options($context);
        $total = $this->roundCount($context);
        $round = min($state['round'], $total);

        switch ($state['phase']) {
            case 'broken':
                return (new LiveView('broken', 'Radio hunt', 10_000))
                    ->text('This hunt is set up wrong: no card has a position. Tell the organiser.', 'warning');

            case 'crew':
                return (new LiveView('crew', 'Radio hunt', 5_000))
                    ->text('You are looking for hidden cards. There is no map. The sensors in the room hear your phone, and the screen tells you if you get warmer or colder.')
                    ->number('crew', (string) $options['crew_question'], 1, 12, 1);

            case 'seek':
                $view = new LiveView('seek', sprintf('Treasure %d of %d', $round, $total), 1_000);
                $this->drawHint($view, $state, $context);
                if ($state['message'] !== null) {
                    $view->text($state['message'], 'warning');
                }
                $view->scan($this->id('scan', $state), 'I see a card: scan it', 'Point the camera at the code on the card.');
                if ($context->now - $state['assigned_at'] >= $options['skip_after_seconds']) {
                    $view->button($this->id('skip', $state), 'Give me another one', 'secondary');
                }

                return $view->text('Walk slowly and quietly. Keep the app open and the screen on.', 'hint')
                    ->progress(count($this->found($state)), $total, 'found');

            case 'hold':
                return (new LiveView('hold', 'Found it!', 3_000))
                    ->text('Stand still at the card. The sensors are learning this place.', 'success')
                    ->hold($this->id('hold', $state), (int) $options['dwell_seconds'], 'Stand still', $this->currentTarget($state, $context));

            case 'ask':
                return (new LiveView('ask', 'One question', 5_000))
                    ->number($this->id('count', $state), (string) $options['count_question'], 0, (int) $options['count_max'], 0, 'headcount');

            default:
                $found = $this->found($state);
                $seconds = array_map(static fn (array $r): int => (int) round($r['found_at'] - $r['assigned_at']), $found);

                return (new LiveView('done', 'Hunt finished', 10_000))
                    ->text('Thank you. Every card you found teaches the library where the signal comes from.', 'success')
                    ->stats([
                        ['label' => 'Treasures found', 'value' => count($found) . ' of ' . $total],
                        ['label' => 'Wrong cards', 'value' => (string) array_sum(array_column($state['rounds'], 'wrong'))],
                        ['label' => 'Fastest', 'value' => $seconds === [] ? '–' : min($seconds) . ' s'],
                    ]);
        }
    }

    public function isDone(array $state): bool
    {
        return $state['phase'] === 'done';
    }

    public function result(array $state): array
    {
        return [
            'schema' => 'monad-live/radio-hunt-result/v1',
            'crew' => $state['crew'],
            'found' => count($this->found($state)),
            'rounds' => array_map(static fn (array $r): array => [
                'target' => $r['target'], 'arm' => $r['arm'], 'outcome' => $r['outcome'],
                'seconds' => isset($r['found_at']) ? round($r['found_at'] - $r['assigned_at'], 1) : null,
                'wrong_cards' => $r['wrong'], 'held_seconds' => $r['held_seconds'] ?? null, 'people_seen' => $r['count'] ?? null,
            ], $state['rounds']),
        ];
    }

    // ── transitions ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function crew(array $state, LiveEvent $event, LiveContext $context): array
    {
        $crew = $event->integer();
        if ($crew === null || $crew < 1 || $crew > 12) {
            return $state;
        }
        $state['crew'] = $crew;

        return $this->nextTarget($state, $context);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function scan(array $state, LiveEvent $event, LiveContext $context): array
    {
        $key = QuestPreflight::targetKey((string) $event->text());
        if ($key === '' || !isset($this->targets($context)[$key])) {
            $context->log('scan_unknown', ['round' => $state['round']]);

            return $this->say($state, 'This code is not part of the hunt. Look for a card.', $context);
        }
        if ($key !== $state['target']) {
            ++$state['wrong'];
            // A known position, whatever the player was looking for.
            $context->log('scan_wrong', ['round' => $state['round'], 'scanned' => $key, 'target' => $state['target'], 'arm' => $state['arm']]);

            return $this->say($state, 'Not this one. Your treasure is another card.', $context);
        }

        $context->log('scan_found', [
            'round' => $state['round'], 'target' => $key, 'arm' => $state['arm'],
            'seconds' => round($context->now - $state['assigned_at'], 2), 'wrong' => $state['wrong'],
        ]);
        $state['phase'] = 'hold';
        $state['rounds'][] = [
            'target' => $key, 'arm' => $state['arm'], 'assigned_at' => $state['assigned_at'],
            'found_at' => $context->now, 'wrong' => $state['wrong'], 'outcome' => 'found',
        ];

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function skip(array $state, LiveContext $context): array
    {
        if ($context->now - $state['assigned_at'] < self::options($context)['skip_after_seconds']) {
            return $state;
        }
        $context->log('skipped', ['round' => $state['round'], 'target' => $state['target'], 'arm' => $state['arm'], 'wrong' => $state['wrong']]);
        $state['rounds'][] = [
            'target' => $state['target'], 'arm' => $state['arm'], 'assigned_at' => $state['assigned_at'],
            'wrong' => $state['wrong'], 'outcome' => 'skipped',
        ];

        return $this->nextTarget($state, $context);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function held(array $state, LiveEvent $event, LiveContext $context): array
    {
        $seconds = $event->data['seconds'] ?? null;
        $state['rounds'][array_key_last($state['rounds'])]['held_seconds'] = is_int($seconds) ? $seconds : null;
        if (self::options($context)['count_question'] === null) {
            return $this->nextTarget($state, $context);
        }
        $state['phase'] = 'ask';

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function counted(array $state, LiveEvent $event, LiveContext $context): array
    {
        $count = $event->integer();
        if ($count === null || $count < 0 || $count > self::options($context)['count_max']) {
            return $state;
        }
        $state['rounds'][array_key_last($state['rounds'])]['count'] = $count;

        return $this->nextTarget($state, $context);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function nextTarget(array $state, LiveContext $context): array
    {
        $options = self::options($context);
        $pool = (string) $options['pool'];
        $targets = $this->targets($context);
        if ($state['round'] >= $this->roundCount($context)) {
            $this->allocator->release($pool, $context->runId);
            $state['phase'] = 'done';
            $context->log('finished', ['found' => count($this->found($state)), 'crew' => $state['crew']]);

            return $state;
        }

        $seen = array_column($state['rounds'], 'target');
        $candidates = array_values(array_diff(array_keys($targets), $seen));
        $last = $seen === [] ? null : ($targets[end($seen)] ?? null);
        if ($last !== null) {
            $far = array_values(array_filter($candidates, static fn (string $k): bool => hypot(
                $targets[$k]['x'] - $last['x'],
                $targets[$k]['y'] - $last['y'],
            ) >= $options['min_separation_m']));
            $candidates = $far !== [] ? $far : $candidates;
        }
        $picked = $this->allocator->assign($pool, $candidates, $context->runId, $context->now, $this->random);
        \assert($picked !== null);

        $state = array_merge($state, [
            'phase' => 'seek', 'round' => $state['round'] + 1, 'target' => $picked['resource'],
            'arm' => $this->drawArm((array) $options['guidance']), 'assigned_at' => $context->now, 'wrong' => 0,
            'in_zone' => false, 'history' => [], 'hint' => null, 'hint_logged_at' => 0.0, 'message' => null,
        ]);
        $context->log('assigned', [
            'round' => $state['round'], 'target' => $state['target'], 'arm' => $state['arm'], 'shared' => $picked['shared'],
        ]);

        return $state;
    }

    // ── guidance ────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function guide(array $state, LiveContext $context): array
    {
        $options = self::options($context);
        $guide = $this->guideOf((string) $state['arm'], (float) $options['zone_radius_m']);
        $medians = $context->subject === null ? [] : $this->signals->nodeMedians(
            $context->subject, $context->now, (float) $options['window_seconds'], (float) $options['max_age_seconds'],
        );

        $targets = $this->targets($context);
        $goal = $targets[$state['target']];
        $others = array_map(
            static fn (array $t): array => [$t['x'], $t['y']],
            array_values(array_diff_key($targets, [$state['target'] => true])),
        );
        $model = $this->model($context);
        $observations = $model->observations($medians);
        $hint = $guide->hint($model, $observations, [$goal['x'], $goal['y']], $others, (bool) $state['in_zone']);

        $state['hint'] = $hint === null ? null : ['closeness' => $hint->closeness, 'in_zone' => $hint->inZone, 'trend' => $this->trend($state, $hint, $guide, $context->now)];
        $state['nodes'] = count($observations);
        if ($hint !== null) {
            $state['in_zone'] = $hint->inZone;
            $state['history'][] = [round($context->now, 2), round($hint->measure, 3)];
            $state['history'] = array_values(array_filter(
                $state['history'],
                static fn (array $h): bool => $context->now - $h[0] <= 2 * self::TREND_SECONDS,
            ));
        }
        if ($context->now - $state['hint_logged_at'] >= self::HINT_LOG_SECONDS) {
            $state['hint_logged_at'] = $context->now;
            $context->log('hint', [
                'round' => $state['round'], 'target' => $state['target'], 'arm' => $state['arm'],
                'nodes' => count($observations), 'broadcasting' => $context->broadcasting,
                'measure' => $hint === null ? null : round($hint->measure, 3),
                'closeness' => $hint === null ? null : round($hint->closeness, 3),
                'in_zone' => $hint?->inZone, 'trend' => $state['hint']['trend'] ?? null,
                'position' => $hint?->position === null ? null : [round($hint->position[0], 2), round($hint->position[1], 2)],
            ]);
        }

        return $state;
    }

    /** @param array<string, mixed> $state @return 'up'|'down'|'flat'|'none' */
    private function trend(array $state, Hint $hint, Guide $guide, float $now): string
    {
        $then = null;
        foreach ($state['history'] as [$at, $measure]) {
            if ($now - $at >= self::TREND_SECONDS - 0.5) {
                $then = $measure; // the newest one that is old enough
            }
        }
        if ($then === null) {
            return 'none';
        }
        $closer = $then - $hint->measure;

        return match (true) {
            $closer >= $guide->trendStep() => 'up',
            $closer <= -$guide->trendStep() => 'down',
            default => 'flat',
        };
    }

    /** @param array<string, mixed> $state */
    private function drawHint(LiveView $view, array $state, LiveContext $context): void
    {
        if ($state['arm'] === 'none') {
            $view->meter(null, 'No signal for this one')->text('Search by eye. It is one of the marked cards.');

            return;
        }
        if ($context->broadcasting === false) {
            $view->meter(null, 'Your phone is silent')->text('Turn Bluetooth on and keep the app open.', 'warning');

            return;
        }
        $hint = $state['hint'];
        if ($hint === null) {
            $view->meter(null, 'Listening…')->text('The sensors do not hear your phone yet. Keep the app open and wait a few seconds.');

            return;
        }
        if ($hint['in_zone']) {
            $view->meter(max(0.8, $hint['closeness']), 'Hot', 'hot', $hint['trend'])
                ->text('You are in the zone. It is one of the cards near you.', 'success');

            return;
        }
        [$label, $tone] = match (true) {
            $hint['closeness'] >= 0.6 => ['Warm', 'warm'],
            $hint['closeness'] >= 0.35 => ['Cool', 'cool'],
            default => ['Cold', 'cold'],
        };
        $view->meter($hint['closeness'], $label, $tone, $hint['trend'])->text(match ($hint['trend']) {
            'up' => 'Warmer. Keep going.',
            'down' => 'Colder. Try another way.',
            default => 'Walk a few steps and watch the needle.',
        });
    }

    // ── small things ────────────────────────────────────────────────────────────────────────

    /**
     * Targets with a surveyed position, keyed as the placement mirror keys them.
     *
     * @return array<string, array{x: float, y: float, target: array<string, string>}>
     */
    private function targets(LiveContext $context): array
    {
        $options = self::options($context);
        $at = $this->positions($context);
        $out = [];
        foreach ((array) ($options['targets'] ?? []) as $target) {
            $key = is_array($target) ? QuestPreflight::targetKey((string) ($target['value'] ?? '')) : '';
            if ($key !== '' && isset($at[$key])) {
                $out[$key] = ['x' => $at[$key][0], 'y' => $at[$key][1], 'target' => $target];
            }
        }

        return $out;
    }

    private function roundCount(LiveContext $context): int
    {
        return min((int) self::options($context)['rounds'], count($this->targets($context)));
    }

    /**
     * @param array<string, mixed> $state
     * @return array{value: string, label: string, room: string, kind: string}
     */
    private function currentTarget(array $state, LiveContext $context): array
    {
        $target = $this->targets($context)[$state['target']]['target'];

        return ['value' => $target['value'], 'label' => $target['label'], 'room' => $target['room'], 'kind' => $target['kind']];
    }

    /** @return array<string, array{0: float, 1: float}> */
    private function positions(LiveContext $context): array
    {
        if (!isset($this->positions[$context])) {
            $out = [];
            foreach ($this->placements->findByFloor((string) (self::options($context)['floor'] ?? '')) as $row) {
                $out[strtolower($row->getKey())] = [$row->getXM(), $row->getYM()];
            }
            $this->positions[$context] = $out;
        }

        return $this->positions[$context];
    }

    private function model(LiveContext $context): RadioModel
    {
        $nodes = array_filter($this->positions($context), static fn (string $k): bool => preg_match('/^monad\d+$/', $k) === 1, ARRAY_FILTER_USE_KEY);

        return new RadioModel($nodes);
    }

    private function guideOf(string $arm, float $zoneRadius): Guide
    {
        return match ($arm) {
            'position' => new PositionGuide($zoneRadius),
            'pattern' => new PatternGuide(),
            default => new NoGuide(),
        };
    }

    /** @param array<string, int> $weights */
    private function drawArm(array $weights): string
    {
        $at = $this->random->getInt(1, array_sum($weights));
        foreach ($weights as $arm => $weight) {
            $at -= $weight;
            if ($at <= 0) {
                return $arm;
            }
        }

        return 'none';
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function say(array $state, string $message, LiveContext $context): array
    {
        $state['message'] = $message;
        $state['message_at'] = $context->now;

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function id(string $widget, array $state): string
    {
        return $widget . '-' . $state['round'];
    }

    /** @param array<string, mixed> $state @return list<array<string, mixed>> */
    private function found(array $state): array
    {
        return array_values(array_filter($state['rounds'], static fn (array $r): bool => $r['outcome'] === 'found'));
    }

    /** @return array<string, mixed> */
    private static function options(LiveContext $context): array
    {
        return array_merge(RadioHuntSpec::DEFAULTS, $context->options);
    }
}
