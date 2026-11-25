<?php

declare(strict_types=1);

namespace App\Quest\Live;

use App\Entity\QuestStepCompletion;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use App\Enum\QuestStepType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * One sync of one live step: lock, apply new events, let time pass, answer with a view.
 *
 * A sync is idempotent in its events and safe to repeat, which is what lets the phone poll on a
 * timer and resend after a lost answer. The run is locked for the transaction, so two screens of
 * the same phone cannot interleave.
 */
final class LiveRunService
{
    /** A phone that was offline sends its queue in one call. More than this is not a queue. */
    public const MAX_EVENTS = 100;

    /** @var array<string, LiveEngine> */
    private array $engines = [];

    /** @param iterable<LiveEngine> $engines */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly LiveStore $store,
        private readonly ClockInterface $clock,
        #[AutowireIterator('app.live_engine')]
        iterable $engines,
    ) {
        foreach ($engines as $engine) {
            $this->engines[$engine->key()] = $engine;
        }
    }

    /**
     * @param list<LiveEvent> $events
     * @return array<string, mixed> the wire answer
     */
    public function sync(User $user, Uuid $stepCompletionId, array $events, ?string $subject, ?bool $broadcasting): array
    {
        $completion = $this->em->find(QuestStepCompletion::class, $stepCompletionId);
        $enrollment = $completion?->getEnrollment();
        // Somebody else's run reads as absent: an id must not confirm that it exists.
        if ($completion === null || $enrollment === null || $enrollment->getUser()?->getId() != $user->getId()) {
            throw new LiveRunException(404, 'No such step.');
        }
        $step = $completion->describeStep();
        if (($step['type'] ?? null) !== QuestStepType::LIVE->value) {
            throw new LiveRunException(404, 'This step is not a live step.');
        }
        if ($enrollment->getStatus() !== QuestEnrollmentStatus::IN_PROGRESS) {
            throw new LiveRunException(409, 'This run is already ' . $enrollment->getStatus()->value . '.');
        }
        $config = (array) ($step['config'] ?? []);
        $engine = $this->engines[$config['engine'] ?? ''] ?? null;
        if ($engine === null) {
            throw new LiveRunException(422, 'This server has no engine for the step.');
        }

        $now = (float) $this->clock->now()->format('U.u');

        return $this->db->transactional(function () use ($completion, $engine, $config, $events, $subject, $broadcasting, $now): array {
            $run = $this->store->lock($completion->getId()->toRfc4122(), $engine->key(), $now);
            $subject ??= $run['subject'];
            $context = new LiveContext($run['id'], (array) ($config['options'] ?? []), $now, $subject, $broadcasting);

            $state = $run['state'];
            if ($run['created']) {
                $context->log('open', ['engine' => $engine->key()]);
                $state = $engine->open($context);
            }
            if (!$run['done']) {
                foreach ($events as $event) {
                    if ($this->store->appendClientEvent($run['id'], $event, $now)) {
                        $state = $engine->handle($state, $event, $context);
                    }
                }
                $state = $engine->advance($state, $context);
            }

            $view = $engine->view($state, $context);
            $done = $engine->isDone($state);
            foreach ($context->drainLog() as $entry) {
                $this->store->appendEngineEvent($run['id'], $entry['kind'], $entry['payload'], $now);
            }
            if (!$run['done']) {
                $this->store->save($run['id'], $state, $subject, $done, $now);
            }

            return [
                'protocol' => LiveProtocol::PROTOCOL,
                'run_id' => $run['id'],
                'engine' => $engine->key(),
                'phase' => $view->phase,
                'view' => $view->toArray(),
                'poll_ms' => $view->pollMs,
                'done' => $done,
                'result' => $done ? $engine->result($state) : null,
                'acked' => array_map(static fn (LiveEvent $e): string => $e->id, $events),
                'server_time_ms' => (int) round($now * 1000),
            ];
        });
    }
}
