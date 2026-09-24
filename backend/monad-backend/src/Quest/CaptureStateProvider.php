<?php

namespace App\Quest;

/**
 * Is a node actually recording right now? (IP-128)
 *
 * A port, not an implementation, because the answer lives in Mimir — outside
 * this application's tenancy — and the arming rule that depends on it must stay
 * testable without a metrics store. Two implementations ship: one that reads the
 * portal's fleet snapshot, and {@see AlwaysCapturing} for tests and for
 * deployments with no observability stack.
 *
 * Why the backend asks at all: a quest whose steps produce measurement must not
 * be offered at a resting node, or its stamp certifies data that was never
 * recorded. The fleet only captures during a scheduled session, so "idle" is the
 * normal state, not an error.
 */
interface CaptureStateProvider
{
    /**
     * True when the node is capturing. MUST fail *open* — an unreachable metrics
     * store means "we do not know", and refusing every quest because telemetry is
     * down would be a worse outcome than crediting a run during an outage.
     */
    public function isCapturing(string $slug): bool;
}
