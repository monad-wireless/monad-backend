<?php

namespace App\Quest;

use App\Entity\Device;
use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use App\Enum\RecurrenceScope;
use App\Repository\QuestEnrollmentRepository;

/**
 * The one place that answers "can this person start this quest at this node?" (IP-128).
 *
 * Single responsibility on purpose: the controller decides HTTP, this decides
 * eligibility, and both the public device page and the authenticated start
 * endpoint ask the same object, so a quest can never look available on the page
 * and 409 on tap.
 *
 * THREE RULES THAT LOOK LIKE DETAILS AND ARE NOT
 *
 * 1. **The cooldown clock is server-side.** `QuestEnrollment::$completedAt` comes
 *    from the request body (`completeQuest()` does
 *    `new \DateTime($requestDto->completed_at)`), so gating on it would let a
 *    client post a backdated finish and farm a node without waiting. The gate
 *    reads `completionReceivedAt`, stamped by the server on receipt.
 *
 * 2. **An abandoned run must not lock a node forever.** Nothing sets
 *    `ABANDONED` automatically — the status is only ever written by hand in
 *    EasyAdmin — so a participant who force-quits mid-quest would otherwise be
 *    permanently `IN_PROGRESS` at that device and permanently excluded from the
 *    sample there. Stale runs age out here instead.
 *
 * 3. **A resting node offers nothing that claims to measure.** The fleet only
 *    captures during a scheduled session; monad02-06 sit at 0 Hz most of the
 *    day. Awarding a stamp for a "measurement" run at an idle node would certify
 *    data that was never recorded. Capture state is an input, not decoration.
 */
final class QuestArmingService
{
    /**
     * How long an IN_PROGRESS enrollment may sit before it is treated as
     * abandoned. Generous: three times the quest's own estimate, floored at two
     * hours, so a slow but genuine run is never stolen out from under someone.
     */
    private const STALE_FLOOR_SECONDS = 7200;

    public function __construct(
        private readonly QuestEnrollmentRepository $enrollments,
        private readonly CaptureStateProvider $captureState,
    ) {
    }

    /**
     * @param bool $requiresCapture whether this quest's steps produce measurement
     */
    public function assess(
        Quest $quest,
        ?User $user,
        ?Device $device,
        bool $requiresCapture = false,
        ?\DateTimeImmutable $now = null,
    ): QuestAvailability {
        $now ??= new \DateTimeImmutable();

        if (!$this->isWithinWindow($quest, $now)) {
            return QuestAvailability::blocked(QuestAvailability::REASON_WINDOW_CLOSED);
        }

        if (null !== $device && !$device->isActive()) {
            return QuestAvailability::blocked(QuestAvailability::REASON_DEVICE_INACTIVE);
        }

        if (null !== $device && !$quest->isArmedAt($device)) {
            return QuestAvailability::blocked(QuestAvailability::REASON_NOT_ARMED);
        }

        // A measurement quest at a resting node would produce a stamp and no data.
        if ($requiresCapture && null !== $device && !$this->captureState->isCapturing($device->getSlug() ?? '')) {
            return QuestAvailability::blocked(QuestAvailability::REASON_NODE_IDLE);
        }

        // Everything below is per-participant. A guest is browsing, not enrolling:
        // the page shows them what the quest IS without pretending to know their
        // history, and the wall lands when they try to earn.
        if (null === $user) {
            return QuestAvailability::available();
        }

        $policy = $quest->getRecurrencePolicy();

        $open = $this->enrollments->findOpenFor($user, $quest, $this->scopeDevice($policy, $device));
        if (null !== $open) {
            if (!$this->isStale($open, $quest, $now)) {
                return QuestAvailability::blocked(QuestAvailability::REASON_IN_PROGRESS);
            }
            // Stale: let the caller reap it and proceed. Reaping is a write, so it
            // is the controller's job, not this read-only assessment's.
        }

        if (null === $policy) {
            // No policy means unlimited — the behaviour every existing quest has
            // today. Adding IP-128 must not silently make legacy quests one-shot.
            return QuestAvailability::available();
        }

        $last = $this->enrollments->findLastCompletedFor($user, $quest, $this->scopeDevice($policy, $device));
        if (null === $last) {
            return QuestAvailability::available();
        }

        $finishedAt = $last->getCompletionReceivedAt();
        if (null === $finishedAt) {
            // Pre-IP-128 rows have no server-side stamp. Treat them as long past
            // rather than trusting the client-supplied completedAt.
            return QuestAvailability::available();
        }

        $retryAt = $policy->nextAvailableAt($finishedAt);
        if ($retryAt > $now) {
            return QuestAvailability::blocked(QuestAvailability::REASON_COOLDOWN, $retryAt);
        }

        return QuestAvailability::available();
    }

    /**
     * An IN_PROGRESS enrollment old enough to be treated as abandoned.
     */
    public function isStale(QuestEnrollment $enrollment, Quest $quest, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $startedAt = $enrollment->getCreatedAt();
        if (!$startedAt instanceof \DateTimeInterface) {
            return false;
        }

        $estimate = (int) ($quest->getEstimatedDuration() ?? 0);
        $budget = max(self::STALE_FLOOR_SECONDS, $estimate * 3);

        return ($now->getTimestamp() - $startedAt->getTimestamp()) > $budget;
    }

    private function isWithinWindow(Quest $quest, \DateTimeImmutable $now): bool
    {
        $from = $quest->getAvailableFrom();
        if ($from instanceof \DateTimeInterface && $now < $from) {
            return false;
        }
        $to = $quest->getAvailableTo();

        return !($to instanceof \DateTimeInterface && $now > $to);
    }

    /** Per-quest scope ignores the device; per-device scope counts against it. */
    private function scopeDevice(?RecurrencePolicy $policy, ?Device $device): ?Device
    {
        if (null === $policy || RecurrenceScope::PER_QUEST === $policy->scope) {
            return null;
        }

        return $device;
    }

    /**
     * Whether a quest's steps produce measurement, and therefore need the node to be capturing.
     *
     * Derived from the step types rather than a flag, so it cannot drift out of sync with what a
     * quest actually does. It lives here, next to the rule that consumes it, because two callers
     * now ask — the public device page and the IP-129 arming matrix — and a quest that "requires
     * capture" on one and not the other would put two different availability reasons on one page.
     */
    public static function producesMeasurement(Quest $quest): bool
    {
        foreach ($quest->getSteps() as $step) {
            $type = $step->getType();
            // ble_advertise counts: a broadcast nobody captures awards a stamp for data never
            // recorded, which is exactly the failure the node-idle gate exists to prevent.
            // probe counts for the same reason and more sharply: its whole output is a dwell
            // window at a surveyed point, and a dwell no receiver heard is thirty seconds of a
            // participant's time spent on nothing.
            if (null !== $type && \in_array($type->value, ['sensor_capture', 'walk_to', 'ble_advertise', 'probe'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return QuestEnrollmentStatus[] statuses that occupy a participant */
    public static function occupyingStatuses(): array
    {
        return [QuestEnrollmentStatus::IN_PROGRESS];
    }
}
