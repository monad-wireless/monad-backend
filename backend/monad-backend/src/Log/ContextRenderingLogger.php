<?php

declare(strict_types=1);

namespace App\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

/**
 * Renders a log record's CONTEXT into its message, because otherwise nobody ever sees it.
 *
 * THE INCIDENT (2026-09-20). A `lab_quest_write` against a quest that had been run failed
 * with a bare "Error while executing tool" for the caller and exactly one line on the
 * server:
 *
 *     [error] Unhandled error during tool execution
 *
 * The exception was not missing. `Mcp\Server\Handler\Request\CallToolHandler` logs it
 * properly, as `['name' => $tool, 'exception' => $e]`. It vanished here: this project has
 * no Monolog, so `logger` is Symfony's fallback
 * {@see \Symfony\Component\HttpKernel\Log\Logger}, whose `format()` interpolates only
 * `{placeholders}` that appear IN the message and discards everything else. A message with
 * no braces therefore throws its whole context away, exception included.
 *
 * The cost was a whole evening spent inventing theories — a payload-size ceiling, a step
 * count, a transport limit — for a constraint violation the server already knew about and
 * could not say out loud. A log line that drops the one field that explains it is worse
 * than no log line, because it looks like evidence.
 *
 * WHY A DECORATOR AND NOT MONOLOG. Monolog is the standard answer and it is a bundle, three
 * environment configs and a dependency, for one defect. This is nine lines of behaviour that
 * works with whatever sits behind it: add Monolog later and this can simply be deleted, with
 * no log line changing shape in between. The context is still PASSED THROUGH unchanged, so a
 * future handler that understands it loses nothing.
 *
 * Scalars render as `key=value`. A `Throwable` renders as its class, message and origin plus
 * the first frames of its trace, because "which line" is the whole question when a log line
 * is all you have. Anything else renders as compact JSON, truncated, so one enormous payload
 * cannot bury the record that explains it.
 */
final class ContextRenderingLogger extends AbstractLogger implements DebugLoggerInterface
{
    /**
     * Enough trace to find the throw site, short enough not to drown the line that matters.
     */
    private const TRACE_FRAMES = 6;

    /**
     * A value that reaches this length is a payload, not a fact about the failure.
     */
    private const MAX_VALUE_CHARS = 400;

    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    public function log($level, $message, array $context = []): void
    {
        $rendered = $this->render((string) $message, $context);

        // Context travels on unchanged. This decorator makes a record READABLE; it is not
        // the record, and a handler installed later must still get the structured fields.
        $this->inner->log($level, $rendered, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $parts = [];
        foreach ($context as $key => $value) {
            // Already interpolated by the inner formatter, so rendering it again would
            // print the same value twice on the same line.
            if (str_contains($message, '{'.$key.'}')) {
                continue;
            }
            $parts[] = $key.'='.$this->describe($value);
        }

        return $parts === [] ? $message : $message.' '.implode(' ', $parts);
    }

    private function describe(mixed $value): string
    {
        if ($value instanceof \Throwable) {
            return $this->describeThrowable($value);
        }
        if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value)) {
            return var_export($value, true);
        }
        if (\is_string($value)) {
            return $this->truncate($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339);
        }

        $json = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $this->truncate($json === false ? '['.get_debug_type($value).']' : $json);
    }

    private function describeThrowable(\Throwable $e): string
    {
        $chain = [];
        // A wrapped driver exception is where the real reason lives: Doctrine's message
        // names the operation, the PDO cause underneath names the constraint.
        for ($current = $e, $depth = 0; $current !== null && $depth < 4; $current = $current->getPrevious(), ++$depth) {
            $chain[] = \sprintf(
                '%s: %s @ %s:%d',
                $current::class,
                $this->truncate($current->getMessage()),
                $current->getFile(),
                $current->getLine(),
            );
        }

        $frames = \array_slice(explode("\n", $e->getTraceAsString()), 0, self::TRACE_FRAMES);

        return '['.implode(' <- ', $chain).' | '.implode(' ', array_map(trim(...), $frames)).']';
    }

    private function truncate(string $value): string
    {
        $flat = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strlen($flat) > self::MAX_VALUE_CHARS
            ? mb_substr($flat, 0, self::MAX_VALUE_CHARS).'…'
            : $flat;
    }

    /**
     * The dev profiler collects through this interface. Delegate when the decorated logger
     * provides it, and answer emptily when it does not, so decorating never silently empties
     * the profiler's log panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogs(?Request $request = null): array
    {
        return $this->inner instanceof DebugLoggerInterface ? $this->inner->getLogs($request) : [];
    }

    public function countErrors(?Request $request = null): int
    {
        return $this->inner instanceof DebugLoggerInterface ? $this->inner->countErrors($request) : 0;
    }

    public function clear(): void
    {
        if ($this->inner instanceof DebugLoggerInterface) {
            $this->inner->clear();
        }
    }
}
