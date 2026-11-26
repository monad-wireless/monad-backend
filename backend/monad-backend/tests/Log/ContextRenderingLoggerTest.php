<?php

namespace App\Tests\Log;

use App\Log\ContextRenderingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * A log record must carry the field that explains it (2026-09-20).
 *
 * Without Monolog, `logger` is Symfony's fallback and its formatter interpolates only
 * `{placeholders}` written into the message. A message with no braces discarded its whole
 * context, so the MCP SDK's "Unhandled error during tool execution" — which logs the
 * exception under an `exception` key, correctly — reached the server log as that sentence
 * and nothing else. A log line that drops the one field explaining it is worse than no line,
 * because it reads as evidence.
 */
class ContextRenderingLoggerTest extends TestCase
{
    /**
     * @return array{0: ContextRenderingLogger, 1: object}
     */
    private function logger(): array
    {
        $spy = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return [new ContextRenderingLogger($spy), $spy];
    }

    public function testAnExceptionInTheContextReachesTheMessage(): void
    {
        [$logger, $spy] = $this->logger();

        $logger->error('Unhandled error during tool execution', [
            'name' => 'lab_quest_write',
            'exception' => new \RuntimeException('violates foreign key constraint'),
        ]);

        $line = $spy->records[0]['message'];

        self::assertStringContainsString('Unhandled error during tool execution', $line);
        self::assertStringContainsString('name=lab_quest_write', $line);
        self::assertStringContainsString('RuntimeException', $line);
        self::assertStringContainsString('violates foreign key constraint', $line);
        // Where it was thrown is the whole question when a log line is all you have.
        self::assertStringContainsString(basename(__FILE__), $line);
    }

    public function testAWrappedCauseSurvives(): void
    {
        // Doctrine names the operation; the driver exception underneath names the constraint.
        [$logger, $spy] = $this->logger();

        $logger->error('write failed', [
            'exception' => new \RuntimeException(
                'An exception occurred while executing a query',
                0,
                new \LogicException('SQLSTATE[23503]: update or delete on table "quest_steps"'),
            ),
        ]);

        self::assertStringContainsString('SQLSTATE[23503]', $spy->records[0]['message']);
    }

    public function testStructuredContextTravelsOnUnchanged(): void
    {
        // This decorator makes a record readable; it is not the record. A handler installed
        // later must still receive the structured fields.
        [$logger, $spy] = $this->logger();
        $context = ['name' => 'lab_quest_write', 'count' => 72];

        $logger->error('write failed', $context);

        self::assertSame($context, $spy->records[0]['context']);
        self::assertSame('error', $spy->records[0]['level']);
    }

    public function testAPlaceholderKeyIsLeftForTheInnerFormatter(): void
    {
        // The inner formatter substitutes `{name}` itself. Appending the same value again
        // here would print it twice on one line, so a key the message already names is
        // skipped and the message passes through untouched.
        [$logger, $spy] = $this->logger();

        $logger->info('Tool {name} executed', ['name' => 'lab_quest_read']);

        self::assertSame('Tool {name} executed', $spy->records[0]['message']);
    }

    public function testAPlaceholderDoesNotSuppressTheOtherKeys(): void
    {
        [$logger, $spy] = $this->logger();

        $logger->error('Tool {name} failed', [
            'name' => 'lab_quest_write',
            'exception' => new \RuntimeException('constraint violation'),
        ]);

        $line = $spy->records[0]['message'];

        self::assertStringStartsWith('Tool {name} failed', $line);
        self::assertStringContainsString('constraint violation', $line);
    }

    public function testAnEmptyContextLeavesTheMessageAlone(): void
    {
        [$logger, $spy] = $this->logger();

        $logger->info('nothing to add');

        self::assertSame('nothing to add', $spy->records[0]['message']);
    }

    public function testAnEnormousValueCannotBuryTheRecord(): void
    {
        [$logger, $spy] = $this->logger();

        $logger->error('write failed', ['arguments' => str_repeat('x', 40_000)]);

        self::assertLessThan(1_000, mb_strlen($spy->records[0]['message']));
        self::assertStringContainsString('…', $spy->records[0]['message']);
    }
}
