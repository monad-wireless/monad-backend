<?php

declare(strict_types=1);

namespace App\Lab\Evidence;

use App\Entity\LabEvidenceManifest;
use App\Entity\LabReferenceReceipt;
use App\Entity\QuestStepCompletion;
use App\Lab\Contract\CountingContracts;
use App\Repository\LabEvidenceManifestRepository;
use App\Repository\LabReferenceReceiptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The reference receipts: a sweep summary in, a reconciled status out (IP-162 §3).
 *
 * `recordCompletion()` is called from the completion endpoint for every step whose frozen
 * snapshot is an observe v2 step. It validates the summary against the snapshot — same protocol
 * digest, same step — writes the receipt as `pending`, and reconciles it at once if an accepted
 * seal already exists for the recording. `reconcileRecording()` is the other arrival order,
 * called from the seal. Both orders reach the same status from the same inputs.
 */
final class SweepReceiptService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LabReferenceReceiptRepository $receipts,
        private readonly LabEvidenceManifestRepository $manifests,
    ) {
    }

    /**
     * Whether a completion's frozen step is a room-sweep step, in which case its `step_data`
     * must be a sweep summary.
     */
    public static function isSweepStep(QuestStepCompletion $completion): bool
    {
        $described = $completion->describeStep();
        if ($described === null || ($described['type'] ?? null) !== 'observe') {
            return false;
        }

        return CountingContracts::isSweepConfig((array) ($described['config'] ?? []));
    }

    /**
     * Validate and record the summary a completion carried. Returns the problems that make the
     * completion itself unacceptable (an empty list means recorded). A recorded summary may still
     * reconcile to `invalid` later; that is an evidence status, not a completion failure.
     *
     * @param array<string, mixed> $stepData
     * @return list<string>
     */
    public function recordCompletion(QuestStepCompletion $completion, array $stepData, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $problems = CountingContracts::sweepSummaryProblems($stepData);
        if ($problems !== []) {
            return $problems;
        }
        $described = $completion->describeStep() ?? [];
        $config = (array) ($described['config'] ?? []);
        if ($stepData['step_completion_id'] !== $completion->getId()?->toRfc4122()) {
            $problems[] = 'step_completion_id: does not name this completion';
        }
        if (($config['protocol_sha256'] ?? null) !== $stepData['protocol_sha256']) {
            $problems[] = 'protocol_sha256: does not match the frozen step snapshot';
        }
        if (($config['protocol_id'] ?? null) !== $stepData['protocol_id']) {
            $problems[] = 'protocol_id: does not match the frozen step snapshot';
        }
        $roomIds = array_map(static fn ($r) => is_array($r) ? ($r['room_id'] ?? null) : null, (array) ($config['rooms'] ?? []));
        if (!in_array($stepData['room_id'], $roomIds, true)) {
            $problems[] = 'room_id: not one of the rooms the frozen step offers';
        }
        if ($problems !== []) {
            return $problems;
        }

        $recording = $stepData['recording_session_id'];
        $sweepId = Uuid::fromString($stepData['sweep_id']);
        $existing = $this->receipts->findForSweep($recording, $sweepId);
        if ($existing !== null) {
            if ($existing->getSummary() == $stepData) {
                // Identical retry: the same receipt, nothing rewritten.
                return [];
            }
            $existing->reconcile(LabReferenceReceipt::STATUS_CONFLICT, $existing->getManifestSha256(), ['summary_conflict'], $now);

            return ['sweep_id: a different summary was already received for this sweep'];
        }

        $receipt = new LabReferenceReceipt(
            $recording,
            $sweepId,
            $completion->getEnrollment()?->getId(),
            $completion->getId(),
            $stepData['protocol_sha256'],
            $stepData,
            $now,
        );
        $this->em->persist($receipt);
        $this->reconcile($receipt, $this->manifests->findAccepted($recording), $now);

        return [];
    }

    /** Reconcile every receipt of a recording against its accepted seal (the upload-first order). */
    public function reconcileRecording(string $recordingSessionId, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $accepted = $this->manifests->findAccepted($recordingSessionId);
        foreach ($this->receipts->findForRecording($recordingSessionId) as $receipt) {
            if ($receipt->getStatus() === LabReferenceReceipt::STATUS_CONFLICT) {
                continue;
            }
            $this->reconcile($receipt, $accepted, $now);
        }
    }

    private function reconcile(LabReferenceReceipt $receipt, ?LabEvidenceManifest $accepted, \DateTimeImmutable $now): void
    {
        if ($accepted === null) {
            $receipt->reconcile(LabReferenceReceipt::STATUS_PENDING, null, ['manifest_not_sealed'], $now);

            return;
        }
        $reasons = [];
        if (!in_array($receipt->getSweepId()->toRfc4122(), $accepted->getSweepIds(), true)) {
            $reasons[] = 'sweep_not_in_manifest';
        }
        $step = $receipt->getStepCompletionId()?->toRfc4122();
        if ($step === null || $accepted->getStepCompletionId() !== $step) {
            $reasons[] = 'step_completion_not_in_manifest';
        }
        $manifest = $accepted->getManifest();
        $enrollment = $receipt->getEnrollmentId()?->toRfc4122();
        if ($enrollment === null || ($manifest['enrollment_id'] ?? null) !== $enrollment) {
            $reasons[] = 'enrollment_not_in_manifest';
        }
        if (!in_array(CountingContracts::SCHEMA_HEADCOUNT_V3, (array) ($manifest['payload_schemas'] ?? []), true)) {
            $reasons[] = 'manifest_carries_no_v3_events';
        }
        $receipt->reconcile(
            $reasons === [] ? LabReferenceReceipt::STATUS_VERIFIED : LabReferenceReceipt::STATUS_INVALID,
            $accepted->getManifestSha256(),
            $reasons,
            $now,
        );
    }
}
