<?php

namespace App\Service;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * Instrument-level telemetry for uploaded lab sessions.
 *
 * The auto-instrumentation already gives HTTP and Doctrine spans for free, which answers "is the
 * API healthy". This class answers the question the *experiment* has: is the fleet's phone
 * instrument producing usable measurements, and if not, which gate is failing.
 *
 * The metrics are chosen to mirror the EXP-P3 gates so a dashboard reads like the instrument's
 * own start-up sequence:
 *
 *   - `monad.lab.session.uploaded`     — artefacts arriving at all
 *   - `monad.lab.session.unpinned`     — sessions whose socket was NOT pinned to the experiment AP.
 *                                        This is the silent failure the whole design guards
 *                                        against: the app reports success, the observer node sees
 *                                        nothing. A non-zero rate here invalidates the runs.
 *   - `monad.lab.traffic.interval_cv`  — realised emission uniformity. The illuminator contract
 *                                        turns on this number: Doppler features need CV well under
 *                                        the broadcast baseline of 1.6–2.5.
 *   - `monad.lab.traffic.delivered`    — delivered / commanded rate.
 *   - `monad.lab.clock.offset_ms`      — |clock offset| against the collector; the sub-100 ms plane
 *                                        gate lives here.
 *
 * Everything is read from the session sidecar rather than measured server-side, because the
 * quantities are properties of the phone's radio link, not of this request.
 */
class LabTelemetry
{
    private CounterInterface $uploads;
    private CounterInterface $uploadFailures;
    private HistogramInterface $uploadBytes;
    private HistogramInterface $uploadSeconds;
    private CounterInterface $unpinned;
    private HistogramInterface $intervalCv;
    private HistogramInterface $delivered;
    private HistogramInterface $clockOffset;

    public function __construct()
    {
        $meter = Globals::meterProvider()->getMeter('monad.lab');

        $this->uploads = $meter->createCounter(
            'monad.lab.session.uploaded',
            'artefacts',
            'Lab-session artefacts accepted by the API',
        );
        // ── The phone -> archive hop ────────────────────────────────────────────────
        // `uploads` above counts artefacts and says nothing about SIZE, DURATION or FAILURE, and
        // until 2026-08-19 this hop wrote no log line either: the `api` service produced 37 lines
        // in seven hours and every one was an Internet scanner probing for `.env`. A session that
        // failed to upload was indistinguishable from a session nobody ran.
        //
        // Bytes and duration are histograms rather than counters because the question is a
        // DISTRIBUTION: "is this upload slow" needs a p95 against the others, not a total. A
        // participant on a lab AP with no route out is the normal case, so the tail is the signal.
        $this->uploadFailures = $meter->createCounter(
            'monad.lab.upload.failed',
            'artefacts',
            'Artefacts that did not reach object storage',
        );
        $this->uploadBytes = $meter->createHistogram(
            'monad.lab.upload.bytes',
            'By',
            'Size of one accepted artefact',
        );
        $this->uploadSeconds = $meter->createHistogram(
            'monad.lab.upload.duration',
            's',
            'Wall time to stream one artefact to object storage',
        );
        $this->unpinned = $meter->createCounter(
            'monad.lab.session.unpinned',
            'sessions',
            'Sessions whose datagram socket was not pinned to the experiment AP',
        );
        $this->intervalCv = $meter->createHistogram(
            'monad.lab.traffic.interval_cv',
            '1',
            'Coefficient of variation of emitted inter-packet intervals',
        );
        $this->delivered = $meter->createHistogram(
            'monad.lab.traffic.delivered',
            '1',
            'Delivered rate as a fraction of the commanded rate',
        );
        $this->clockOffset = $meter->createHistogram(
            'monad.lab.clock.offset_ms',
            'ms',
            'Absolute clock offset against the collector',
        );
    }

    /**
     * Record one accepted artefact.
     *
     * `artefact` is the filename, which is a CLOSED set — the session's artefact names are fixed by
     * `LabSession` (`pose.tsv`, `beacons.tsv`, `metadata.json`, …). It is safe as a label for the
     * same reason `participant` is: bounded by the design, not by how many sessions run.
     *
     * @param array<string, mixed> $attributes
     */
    public function artefactAccepted(string $filename, array $attributes = []): void
    {
        $this->uploads->add(1, ['artefact' => $filename] + $attributes);
    }

    /**
     * Record the size and cost of one accepted artefact.
     *
     * Separate from [artefactAccepted] because the counter must increment even when the caller has
     * no timing to report — a count that depends on a stopwatch is a count that goes missing.
     *
     * @param array<string, mixed> $attributes
     */
    public function artefactStored(string $filename, int $bytes, float $seconds, array $attributes = []): void
    {
        $labels = ['artefact' => $filename] + $attributes;
        if ($bytes > 0) {
            $this->uploadBytes->record($bytes, $labels);
        }
        if ($seconds > 0.0) {
            $this->uploadSeconds->record($seconds, $labels);
        }
    }

    /**
     * Record an artefact that did not make it.
     *
     * @param array<string, mixed> $attributes
     */
    public function artefactFailed(string $filename, array $attributes = []): void
    {
        $this->uploadFailures->add(1, ['artefact' => $filename] + $attributes);
    }

    /**
     * Fold a completed session's sidecar into metrics.
     *
     * Called only for `metadata.json`, which the client uploads last precisely so that its arrival
     * marks the session complete — a partial upload therefore never produces a misleading metric.
     */
    public function sessionCompleted(string $rawSidecar): void
    {
        $sidecar = json_decode($rawSidecar, true);
        if (!is_array($sidecar)) {
            return;
        }

        $radio = $sidecar['radio'] ?? [];
        $summary = $sidecar['summary'] ?? [];
        $environment = $sidecar['environment'] ?? [];
        $identity = $sidecar['identity'] ?? [];

        $attributes = [
            'site' => (string) ($identity['site'] ?? ''),
            'platform' => (string) ($environment['platform'] ?? ''),
            'ap' => (string) ($radio['ap_id'] ?? ''),
        ];

        if (($radio['socket_pinned'] ?? false) !== true) {
            $this->unpinned->add(1, $attributes);
        }

        $commanded = (float) ($summary['commanded_rate_hz'] ?? 0.0);
        $achieved = (float) ($summary['achieved_rate_hz'] ?? 0.0);

        if ($commanded > 0.0) {
            $this->intervalCv->record((float) ($summary['interval_cv'] ?? 0.0), $attributes);
            $this->delivered->record($achieved / $commanded, $attributes);
        }

        // A session with no clock samples reports offset 0, which would be indistinguishable from
        // a perfectly disciplined one — record only when the discipline actually ran.
        if (($summary['clock_delay_ms'] ?? 0.0) > 0.0) {
            $this->clockOffset->record(abs((float) ($summary['clock_offset_ms'] ?? 0.0)), $attributes);
        }
    }
}
