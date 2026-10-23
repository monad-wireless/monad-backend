<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\HandsetRepository;
use App\Repository\LabSessionRepository;
use App\Repository\QuestEnrollmentRepository;
use App\Repository\QuestRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the session register from the upload path and from the backfill (IP-149).
 *
 * Two entry points, matching the two things an upload can be: one more artefact,
 * or the sidecar that completes the session. Both are called AFTER the S3 write
 * succeeded and in the same request, so a failure here is a logged 500 the client
 * retries — never a session that exists on S3 with no row and no trace.
 *
 * The sidecar's foreign keys are RESOLVED, never trusted. `identity.enrollment_id`
 * becomes `enrollment_id` only when a row with that id exists; the same for the
 * quest and for the handset (`environment.handset.handset_id` → `handsets`).
 * A sidecar naming an enrollment this database has never seen (a bench build
 * against a different backend, say) lands with `enrollment_id` null and its claim
 * still readable in the stored sidecar.
 */
final class LabSessionRegister
{
    public function __construct(
        private readonly LabSessionRepository $sessions,
        private readonly QuestEnrollmentRepository $enrollments,
        private readonly QuestRepository $quests,
        private readonly HandsetRepository $handsets,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function artefactStored(
        string $sessionId,
        string $participantId,
        ?User $user,
        string $filename,
        int $bytes,
        string $contentType,
        string $transport,
        ?\DateTimeImmutable $now = null,
    ): void {
        $this->sessions->recordArtefact(
            self::sanitize($sessionId),
            self::sanitize($participantId),
            $user?->getId()?->toRfc4122(),
            self::filename($filename),
            ['bytes' => $bytes, 'content_type' => $contentType, 'transport' => $transport],
            $now,
        );
    }

    /**
     * @param string $rawSidecar the `metadata.json` bytes
     * @return bool false when the sidecar is not a JSON object (nothing was written)
     */
    public function sessionCompleted(
        string $sessionId,
        string $participantId,
        ?User $user,
        string $rawSidecar,
        ?\DateTimeImmutable $now = null,
    ): bool {
        $sidecar = json_decode($rawSidecar, true);
        if (!is_array($sidecar)) {
            $this->logger->warning('[lab-register] sidecar for session {session_id} is not a JSON object; row left incomplete', [
                'session_id' => $sessionId,
            ]);

            return false;
        }

        $identity = is_array($sidecar['identity'] ?? null) ? $sidecar['identity'] : [];
        $environment = is_array($sidecar['environment'] ?? null) ? $sidecar['environment'] : [];
        $handsetBlock = is_array($environment['handset'] ?? null) ? $environment['handset'] : [];

        $enrollmentId = $this->resolveUuid($identity['enrollment_id'] ?? null, fn (Uuid $id) => $this->enrollments->find($id) !== null);
        $questId = $this->resolveUuid($identity['quest_id'] ?? null, fn (Uuid $id) => $this->quests->find($id) !== null);

        $handsetId = null;
        $installation = $handsetBlock['handset_id'] ?? null;
        if (is_string($installation) && $installation !== '') {
            $handsetId = $this->handsets->findByInstallationId($installation)?->getId()->toRfc4122();
        }

        // The participant key IS the backend user id when the app used its default
        // (`user.backendId`), which is every session so far. A backfilled row has no
        // authenticated uploader, so this is how it gets its user.
        $userId = $user?->getId()?->toRfc4122()
            ?? $this->resolveUuid($participantId, fn (Uuid $id) => $this->users->find($id) !== null);

        $this->sessions->complete(
            self::sanitize($sessionId),
            self::sanitize($participantId),
            $userId,
            $sidecar,
            $enrollmentId,
            $questId,
            $handsetId,
            $now,
        );

        return true;
    }

    /** @param callable(Uuid): bool $exists */
    private function resolveUuid(mixed $value, callable $exists): ?string
    {
        if (!is_string($value) || !Uuid::isValid($value)) {
            return null;
        }
        $uuid = Uuid::fromString($value);

        return $exists($uuid) ? $uuid->toRfc4122() : null;
    }

    /** Same rule as `S3Service::sanitizeIdentifier`, so the row id equals the S3 prefix segment. */
    public static function sanitize(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? '';
        $clean = trim($clean, '.');

        return $clean === '' ? 'unknown' : substr($clean, 0, 128);
    }

    /** Same rule as `S3Service::sanitizeFilename`, so the artefact key equals the S3 object name. */
    public static function filename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($filename)) ?? '';

        return $filename === '' ? 'file' : $filename;
    }
}
