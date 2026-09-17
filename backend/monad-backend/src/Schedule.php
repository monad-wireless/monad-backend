<?php

namespace App;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The `default` schedule, consumed by the worker as the `scheduler_default` transport
 * (`messenger:consume scheduler_default async`, the `worker` service in docker-compose.deploy.yml).
 *
 * IP-157: `app:notifications:dispatch-due` every minute, as a RunCommandMessage. The command is
 * idempotent (only `sent_at IS NULL` rows are due, and the dispatcher refuses a second send), so
 * `processOnlyLastMissedRun` collapsing a backlog after downtime loses nothing: one run sends
 * everything that became due meanwhile.
 *
 * IP-157 Phase 5: `app:beta:purge --apply` once a day. The retention window is a day-granularity
 * promise in the /join consent text, so a daily tick is enough; an odd minute keeps it off the
 * dispatcher's tick. Idempotent too: a withdrawn row is no longer a purge candidate.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(RecurringMessage::every('1 minute', new RunCommandMessage('app:notifications:dispatch-due')))
            // `every` rather than `cron`: the cron trigger needs dragonmantank/cron-expression,
            // one more dependency for one daily tick. `from` anchors the tick at 03:17.
            ->add(RecurringMessage::every('1 day', new RunCommandMessage('app:beta:purge --apply'), from: '03:17'))
        ;
    }
}
