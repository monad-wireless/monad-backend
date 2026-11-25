<?php

declare(strict_types=1);

namespace App\Quest\Live;

/**
 * What an engine may see of one sync: the run, its frozen options, the clock, and a log.
 *
 * `now` is fixed for the whole sync, so two decisions in one call agree about the time.
 */
final class LiveContext
{
    /** @var list<array{kind: string, payload: array<string, mixed>}> */
    private array $logged = [];

    /** @param array<string, mixed> $options the step's frozen `options` */
    public function __construct(
        public readonly string $runId,
        public readonly array $options,
        public readonly float $now,
        /** The service UUID this phone advertises, or null when it is not on air. */
        public readonly ?string $subject = null,
        public readonly ?bool $broadcasting = null,
    ) {
    }

    /**
     * Record an engine decision on the run's timeline.
     *
     * @param array<string, mixed> $payload
     */
    public function log(string $kind, array $payload = []): void
    {
        $this->logged[] = ['kind' => $kind, 'payload' => $payload];
    }

    /** @return list<array{kind: string, payload: array<string, mixed>}> */
    public function drainLog(): array
    {
        $out = $this->logged;
        $this->logged = [];

        return $out;
    }
}
