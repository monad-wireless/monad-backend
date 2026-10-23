<?php

declare(strict_types=1);

namespace App\Lab\Evidence;

use App\Entity\LabSession;
use App\Entity\User;
use App\Repository\LabSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seal a recording from the `evidence-manifest.json` the phone uploaded beside its artefacts.
 *
 * The app writes the manifest into the bucket before the sidecar and never calls
 * `POST …/evidence/seal` (found 2026-10-20: every Counting recording since the v3 quest went
 * live stayed `manifest_not_sealed`). The manifest is the phone's own document, so sealing it
 * here changes who triggers the seal, not what is sealed: the digest is taken over the uploaded
 * bytes, and {@see EvidenceSealService} applies the same ownership and hash rules the route does.
 *
 * Two callers: the upload path, when the sidecar (or a late manifest) lands, and
 * `app:lab-evidence:seal-uploaded` for recordings uploaded before this existed.
 */
final class UploadedManifestSealer
{
    public const MANIFEST = 'evidence-manifest.json';

    /** Same cap the seal route applies to a request body. */
    public const MAX_MANIFEST_BYTES = 256 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LabSessionRepository $sessions,
        private readonly ArtefactReader $artefacts,
        private readonly EvidenceSealService $seal,
    ) {
    }

    /**
     * Seal a complete recording whose register lists an uploaded manifest. `null` when there is
     * nothing to seal yet: an unknown recording, one without a sidecar, or one without a manifest.
     */
    public function sealIfUploaded(string $recordingSessionId, ?User $uploader): ?SealOutcome
    {
        $session = $this->sessions->find($recordingSessionId);
        if (!$session instanceof LabSession) {
            return null;
        }
        // The register writes by native SQL, so an entity loaded earlier in the request still
        // shows the row before this upload: incomplete, without the manifest.
        $this->em->refresh($session);
        if (!$session->isComplete() || !$session->hasArtefact(self::MANIFEST)) {
            return null;
        }

        return $this->sealFromBucket($session, $uploader);
    }

    /** Read the uploaded manifest back from the bucket and seal it; `null` when it is not there. */
    public function sealFromBucket(LabSession $session, ?User $uploader): ?SealOutcome
    {
        $raw = $this->artefacts->read($session->getParticipantId(), $session->getId(), self::MANIFEST, self::MAX_MANIFEST_BYTES);
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof TooLargeToVerify) {
            return SealOutcome::invalid(null, [sprintf('%s: %d bytes exceeds the %d-byte manifest cap', self::MANIFEST, $raw->bytes, self::MAX_MANIFEST_BYTES)]);
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || array_is_list($manifest)) {
            return SealOutcome::invalid(null, [self::MANIFEST . ': not a JSON object']);
        }

        return $this->seal->seal($session, $manifest, $uploader, $raw);
    }
}
