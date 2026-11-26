<?php

namespace App\Dto\Lab;

use App\Constants\ErrorCode;
use App\Exception\ValidationException;

/**
 * Turns a decoded request body into parsed events plus a list of refusals.
 *
 * Separate from the controller so the batch rules are testable without a kernel, a firewall or a
 * database. They are worth testing: a participant device flushes its whole session in one body,
 * and the rule that one unusable row must not cost the other forty is the difference between
 * losing one scan and losing a fold.
 */
final class GroundTruthBatch
{
    /**
     * One flush is one participant's whole session — a few dozen scans. Three orders of magnitude
     * of headroom: high enough that no honest client meets it, low enough to bound a request.
     */
    public const MAX_BATCH = 1000;

    /**
     * @param GroundTruthScanDto[]           $events
     * @param list<array<string, string>>    $rejected
     */
    private function __construct(
        public readonly array $events,
        public readonly array $rejected,
    ) {
    }

    /**
     * @param mixed $payload the json_decode'd body
     *
     * @throws ValidationException when the body as a whole is unusable
     */
    public static function fromPayload(mixed $payload): self
    {
        if (!is_array($payload)) {
            throw new ValidationException(ErrorCode::LAB_GROUND_TRUTH_MALFORMED_BODY);
        }

        // Batch or bare event. A single scan is what a phone sends the moment it regains
        // connectivity mid-session, and requiring an envelope would be ceremony at exactly the
        // wrong time.
        $rows = $payload['events'] ?? null;
        if ($rows === null) {
            $rows = [$payload];
        }

        if (!is_array($rows)) {
            throw new ValidationException(ErrorCode::LAB_GROUND_TRUTH_MALFORMED_BODY);
        }
        if ($rows === []) {
            throw new ValidationException(ErrorCode::LAB_GROUND_TRUTH_EMPTY_BATCH);
        }
        if (count($rows) > self::MAX_BATCH) {
            throw new ValidationException(ErrorCode::LAB_GROUND_TRUTH_BATCH_TOO_LARGE);
        }

        $events = [];
        $rejected = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $rejected[] = [
                    'scan_nonce' => '',
                    'status' => 'rejected',
                    'reason' => sprintf('event %s is not an object', (string) $index),
                ];
                continue;
            }

            try {
                $events[] = GroundTruthScanDto::fromArray($row);
            } catch (\InvalidArgumentException $e) {
                // Named by nonce wherever one is legible, so an operator can find the offending
                // scan on the handset that sent it.
                $rejected[] = [
                    'scan_nonce' => is_string($row['scan_nonce'] ?? null) ? $row['scan_nonce'] : '',
                    'status' => 'rejected',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return new self($events, $rejected);
    }
}
