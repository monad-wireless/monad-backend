<?php

namespace App\Tests\Quest;

use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use App\Quest\RealisedRoute;
use PHPUnit\Framework\TestCase;

/**
 * IP-145: projecting a quest's declared steps onto the route one enrollment drew.
 *
 * This class exists because the feature it serves shipped once doing nothing. The realised
 * route was stored on the enrollment and read by no one, so a pooled quest was written,
 * accepted, and served every walker the same declared order. Every test here is a way that
 * can happen again.
 */
class RealisedRouteTest extends TestCase
{
    /**
     * A hunt over three points: start, three walk_to/probe pairs, finish.
     *
     * @return list<QuestStep>
     */
    private function declared(): array
    {
        $steps = [];
        $steps[] = $this->step(0, QuestStepType::START, []);
        $order = 1;
        foreach (['MONAD-FP-01', 'monad02', 'MONAD-FP-03'] as $key) {
            $steps[] = $this->step($order++, QuestStepType::WALK_TO, []);
            $steps[] = $this->step($order++, QuestStepType::PROBE, [
                'targets' => [['value' => "https://monad.dubec.dev/m/$key"]],
            ]);
        }
        $steps[] = $this->step($order, QuestStepType::FINISH, []);

        return $steps;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function step(int $order, QuestStepType $type, array $config): QuestStep
    {
        $step = new QuestStep();
        $step->setOrder($order);
        $step->setType($type);
        $step->setConfig($config);
        $step->setName("step $order");

        return $step;
    }

    /** @param list<QuestStep> $steps @return list<string> */
    private function shape(array $steps): array
    {
        return array_map(static function (QuestStep $s): string {
            $value = $s->getConfig()['targets'][0]['value'] ?? null;
            $key = is_string($value) ? substr(strrchr($value, '/') ?: '/', 1) : '';
            return $s->getType()->value . ($key !== '' ? ":$key" : '');
        }, $steps);
    }

    public function testNoRouteServesTheDeclaredOrder(): void
    {
        // Every quest before IP-145, and every quest without a pool.
        self::assertSame(
            $this->shape($this->declared()),
            $this->shape(RealisedRoute::apply($this->declared(), null)),
        );
        self::assertSame(
            $this->shape($this->declared()),
            $this->shape(RealisedRoute::apply($this->declared(), [])),
        );
    }

    public function testARouteReordersAndSelects(): void
    {
        $served = RealisedRoute::apply($this->declared(), ['MONAD-FP-03', 'MONAD-FP-01']);

        self::assertSame(
            ['start', 'walk_to', 'probe:MONAD-FP-03', 'walk_to', 'probe:MONAD-FP-01', 'finish'],
            $this->shape($served),
        );
    }

    public function testEachWalkToTravelsWithItsOwnProbe(): void
    {
        // The bug this replaced: keying only on the probe stranded every walk_to, so a walker
        // got "scan it" with no "go to it" and the plan that tells them where.
        $served = RealisedRoute::apply($this->declared(), ['monad02']);
        $types = array_map(static fn (QuestStep $s): string => $s->getType()->value, $served);

        self::assertSame(['start', 'walk_to', 'probe', 'finish'], $types);
    }

    public function testTheStartStepIsAlwaysServed(): void
    {
        // Without it the quest declares no `features` block, every field defaults to false,
        // and the run records dwells with nothing on air. The symptom is a gap, not an error.
        $served = RealisedRoute::apply($this->declared(), ['MONAD-FP-03']);

        self::assertSame(QuestStepType::START, $served[0]->getType());
        self::assertSame(QuestStepType::FINISH, end($served)->getType());
    }

    public function testAnUnknownKeyIsDroppedNotGuessedAt(): void
    {
        $served = RealisedRoute::apply($this->declared(), ['MONAD-FP-01', 'MONAD-FP-99']);

        self::assertSame(
            ['start', 'walk_to', 'probe:MONAD-FP-01', 'finish'],
            $this->shape($served),
        );
    }

    public function testARouteMatchingNothingDegradesWholeNotPartially(): void
    {
        // A stale pool after the cards were re-laid. Serving the empty match would be a quest
        // of start-then-finish that reads as complete; serving the declared set is a longer
        // walk than intended and loses nothing.
        $served = RealisedRoute::apply($this->declared(), ['gone-1', 'gone-2']);

        self::assertSame($this->shape($this->declared()), $this->shape($served));
    }

    public function testDeclaredOrderIsRespectedWhateverOrderTheRowsArriveIn(): void
    {
        // Doctrine gives no ordering guarantee on a OneToMany collection.
        $shuffled = $this->declared();
        $shuffled = [$shuffled[4], $shuffled[0], $shuffled[7], $shuffled[2], $shuffled[1], $shuffled[3], $shuffled[5], $shuffled[6]];

        self::assertSame(
            $this->shape($this->declared()),
            $this->shape(RealisedRoute::apply($shuffled, null)),
        );
    }
}
