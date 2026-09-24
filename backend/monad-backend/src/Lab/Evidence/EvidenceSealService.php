<?php

declare(strict_types=1);

namespace App\Lab\Evidence;

use App\Entity\LabEvidenceManifest;
use App\Entity\LabSession;
use App\Entity\QuestStepCompletion;
use App\Entity\User;
use App\Lab\Contract\CanonicalJson;
use App\Lab\Contract\CountingContracts;
use App\Repository\LabEvidenceManifestRepository;
use App\Repository\LabSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Seal a recording's evidence under `monad-lab/evidence-manifest/v1` (IP-162 §3).
 *
 * The order is the whole point. The app has already uploaded its bytes to the bucket (staged);
 * this service reads them back, hashes them, compares against the manifest, and only then writes
 * the seal row in one database transaction. S3 and PostgreSQL do not share a transaction and
 * nothing here pretends they do: a failed database write leaves the staged bytes untouched and
 * the next call, or `app:lab-evidence:reconcile`, verifies them again.
 *
 * Idempotent on the manifest digest: the same bytes sealed twice return the same receipt. A
 * different digest for a recording that already holds an accepted seal is a CONFLICT — recorded,
 * refused, and never a reseal. An amendment is a separately identified revision through another
 * path, not a second manifest through this one.
 */
final class EvidenceSealService
{
    /** Objects above this are not read here; their hash is reported unverified, never guessed. */
    public const MAX_VERIFY_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LabEvidenceManifestRepository $manifests,
        private readonly LabSessionRepository $sessions,
        private readonly ArtefactReader $artefacts,
        private readonly SweepReceiptService $receipts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $manifest the decoded request body
     * @param string|null $rawBody the request bytes, so the digest is taken over the sender's
     *                             document with `{}` and `[]` kept apart; null for a document
     *                             this backend built itself
     */
    public function seal(LabSession $session, array $manifest, ?User $uploader, ?string $rawBody = null, ?\DateTimeImmutable $now = null): SealOutcome
    {
        $now ??= new \DateTimeImmutable();

        $problems = CountingContracts::evidenceManifestProblems($manifest);
        if ($problems !== []) {
            return SealOutcome::invalid(null, $problems);
        }
        try {
            $digest = $rawBody !== null ? CanonicalJson::sha256OfJson($rawBody) : CanonicalJson::sha256($manifest);
        } catch (\InvalidArgumentException|\JsonException $e) {
            return SealOutcome::invalid(null, [$e->getMessage()]);
        }

        $accepted = $this->manifests->findAccepted($session->getId());
        if ($accepted !== null && $accepted->getManifestSha256() !== $digest) {
            $row = $this->upsert($session, $digest, $manifest, $uploader, $now);
            $row->record(LabEvidenceManifest::STATE_CONFLICT, [], ['seal_conflict:' . $accepted->getManifestSha256()], $now);
            $this->em->flush();

            return SealOutcome::conflict($row, $accepted);
        }

        $row = $this->upsert($session, $digest, $manifest, $uploader, $now);

        return $this->verifyRow($session, $row, $now);
    }

    /**
     * Re-verify a pending seal — after the sidecar or a late artefact landed.
     */
    public function reverify(LabEvidenceManifest $row, ?\DateTimeImmutable $now = null): SealOutcome
    {
        $session = $this->sessions->find($row->getRecordingSessionId());
        if (!$session instanceof LabSession) {
            return SealOutcome::of($row);
        }

        return $this->verifyRow($session, $row, $now ?? new \DateTimeImmutable());
    }

    private function verifyRow(LabSession $session, LabEvidenceManifest $row, \DateTimeImmutable $now): SealOutcome
    {
        if ($row->isAccepted()) {
            // Identical retry of the accepted seal: the original receipt, nothing rewritten.
            return SealOutcome::of($row);
        }
        $manifest = $row->getManifest();
        $digest = $row->getManifestSha256();
        $ownership = $this->ownershipProblems($session, $manifest, $row->getUploadedBy());
        if ($ownership !== []) {
            $row->record(LabEvidenceManifest::STATE_INVALID, [], $ownership, $now);
            $this->em->flush();

            return SealOutcome::of($row);
        }

        [$verification, $reasons, $missing, $unverified] = $this->verify($session, $manifest);
        $state = match (true) {
            $reasons !== [] => LabEvidenceManifest::STATE_INVALID,
            $missing !== [] || $unverified !== [] => LabEvidenceManifest::STATE_PENDING,
            default => LabEvidenceManifest::STATE_ACCEPTED,
        };
        $pendingReasons = array_merge(
            array_map(static fn (string $n) => 'artifact_missing:' . $n, $missing),
            array_map(static fn (string $n) => 'artifact_unverified:' . $n, $unverified),
        );
        $row->record($state, $verification, array_merge($reasons, $pendingReasons), $now);

        $this->em->beginTransaction();
        try {
            $this->em->flush();
            if ($state === LabEvidenceManifest::STATE_ACCEPTED) {
                // The observed digests join the register's artefact inventory, so the admin's
                // artefact table and the analysis read them without opening the manifest.
                $this->sessions->recordObservedDigests($session->getId(), $verification, $now);
                $this->receipts->reconcileRecording($session->getId(), $now);
                // The receipts' new statuses are entity mutations; they reach the database only
                // through this flush, inside the same transaction as the seal row.
                $this->em->flush();
            }
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            $this->logger->error('[lab-evidence] seal of {session} ({digest}) failed to persist: {error}', [
                'session' => $session->getId(),
                'digest' => $digest,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return SealOutcome::of($row, $missing);
    }

    /**
     * Who may seal what. Beyond "the row exists": the uploader must own the recording and the
     * enrollment, the enrollment must own the step, and the manifest's identities must agree with
     * the sidecar the register resolved. A superadmin may seal on the participant's behalf.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function ownershipProblems(LabSession $session, array $manifest, ?User $uploader): array
    {
        $out = [];
        if ($manifest['recording_session_id'] !== $session->getId()) {
            $out[] = 'recording_session_id: does not name this recording';
        }
        $sessionUser = $session->getUser();
        $superadmin = $uploader !== null && in_array('ROLE_SUPERADMIN', $uploader->getRoles(), true);
        if ($uploader === null) {
            $out[] = 'uploader: unauthenticated';
        } elseif ($sessionUser !== null && !$superadmin && $sessionUser->getId()?->toRfc4122() !== $uploader->getId()?->toRfc4122()) {
            $out[] = 'uploader: does not own this recording';
        }

        $enrollment = $session->getEnrollment();
        if ($enrollment !== null) {
            if ($enrollment->getId()?->toRfc4122() !== $manifest['enrollment_id']) {
                $out[] = 'enrollment_id: does not match the recording\'s enrollment';
            }
            if ($uploader !== null && !$superadmin && $enrollment->getUser()?->getId()?->toRfc4122() !== $uploader->getId()?->toRfc4122()) {
                $out[] = 'enrollment_id: not owned by the uploader';
            }
            if ($enrollment->getQuest()?->getId()?->toRfc4122() !== $manifest['quest_id']) {
                $out[] = 'quest_id: does not match the enrollment\'s quest';
            }
            $step = $this->em->getRepository(QuestStepCompletion::class)->find(Uuid::fromString($manifest['step_completion_id']));
            if (!$step instanceof QuestStepCompletion) {
                $out[] = 'step_completion_id: unknown';
            } elseif ($step->getEnrollment()?->getId()?->toRfc4122() !== $enrollment->getId()?->toRfc4122()) {
                $out[] = 'step_completion_id: belongs to another enrollment';
            }
        } else {
            // The register resolved no enrollment: the sidecar named none, or one this database
            // does not hold. A manifest cannot be verified against an enrollment that is not here.
            $out[] = 'enrollment_id: the recording resolved no enrollment in this database';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>, 2: list<string>, 3: list<string>}
     */
    private function verify(LabSession $session, array $manifest): array
    {
        $verification = [];
        $reasons = [];
        $missing = [];
        $unverified = [];
        foreach ($manifest['artifacts'] as $artifact) {
            $name = $artifact['name'];
            $entry = ['expected' => $artifact['sha256'], 'bytes' => $artifact['bytes'], 'observed' => null, 'verified' => false];
            $body = $this->artefacts->read($session->getParticipantId(), $session->getId(), $name, self::MAX_VERIFY_BYTES);
            if ($body === null) {
                $missing[] = $name;
            } elseif ($body instanceof TooLargeToVerify) {
                $unverified[] = $name;
                $entry['observed_bytes'] = $body->bytes;
            } else {
                $observed = CanonicalJson::sha256Bytes($body);
                $entry['observed'] = $observed;
                $entry['observed_bytes'] = strlen($body);
                if ($observed !== $artifact['sha256']) {
                    $reasons[] = 'artifact_hash_mismatch:' . $name;
                } elseif (strlen($body) !== $artifact['bytes']) {
                    $reasons[] = 'artifact_size_mismatch:' . $name;
                } else {
                    $entry['verified'] = true;
                }
            }
            $verification[$name] = $entry;
        }

        return [$verification, $reasons, $missing, $unverified];
    }

    /** @param array<string, mixed> $manifest */
    private function upsert(LabSession $session, string $digest, array $manifest, ?User $uploader, \DateTimeImmutable $now): LabEvidenceManifest
    {
        $row = $this->manifests->findByIdentity($session->getId(), $digest);
        if ($row === null) {
            $row = new LabEvidenceManifest($session->getId(), $digest, $manifest, $uploader, $now);
            $this->em->persist($row);
        }

        return $row;
    }
}
