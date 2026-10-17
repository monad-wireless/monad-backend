<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LabSession;
use App\Entity\User;
use App\Lab\Evidence\EvidenceSealService;
use App\Repository\LabEvidenceManifestRepository;
use App\Repository\LabReferenceReceiptRepository;
use App\Repository\LabSessionRepository;
use App\Service\LabSessionRegister;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The evidence seal and the reference receipts of one recording (IP-162 §3).
 *
 * Both routes are owner-or-operator: the participant who uploaded the recording may seal it and
 * read its status; a superadmin may do either on their behalf. Neither route returns a radio
 * estimate or a model output — a participant collecting a reference must stay blind to them.
 */
final class LabEvidenceController extends AbstractController
{
    private const MAX_MANIFEST_BYTES = 256 * 1024;

    public function __construct(
        private readonly LabSessionRepository $sessions,
        private readonly EvidenceSealService $seal,
        private readonly LabEvidenceManifestRepository $manifests,
        private readonly LabReferenceReceiptRepository $receipts,
    ) {
    }

    #[Route('/api/lab/sessions/{recordingSessionId}/evidence/seal', name: 'api_lab_evidence_seal', methods: ['POST'])]
    #[OA\Post(
        path: '/api/lab/sessions/{recordingSessionId}/evidence/seal',
        summary: 'Seal a recording\'s uploaded evidence under a monad-lab/evidence-manifest/v1 (IP-162)',
        description: 'Body: the manifest. The server hashes every listed artefact it holds and compares. '
            . 'Returns status pending (an artefact is not up yet), verified (sealed), invalid (structure, ownership or a hash disagreed) '
            . 'or conflict (409: a different manifest is already sealed for this recording). Identical retries return the same receipt.',
        tags: ['Lab'],
    )]
    #[OA\Response(response: 200, description: 'pending or verified')]
    #[OA\Response(response: 400, description: 'not a JSON object')]
    #[OA\Response(response: 403, description: 'not the owner of this recording')]
    #[OA\Response(response: 404, description: 'unknown recording')]
    #[OA\Response(response: 409, description: 'a different manifest is already sealed')]
    #[OA\Response(response: 422, description: 'invalid manifest, with reasons')]
    public function sealEvidence(string $recordingSessionId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        if (strlen($request->getContent()) > self::MAX_MANIFEST_BYTES) {
            return $this->json(['error' => 'Manifest too large'], Response::HTTP_BAD_REQUEST);
        }
        $manifest = json_decode($request->getContent(), true);
        if (!is_array($manifest) || array_is_list($manifest)) {
            return $this->json(['error' => 'The body must be a JSON object'], Response::HTTP_BAD_REQUEST);
        }

        $session = $this->sessions->find(LabSessionRegister::sanitize($recordingSessionId));
        if (!$session instanceof LabSession) {
            return $this->json(['error' => 'Unknown recording'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->mayRead($session, $user)) {
            return $this->json(['error' => 'This recording does not belong to you'], Response::HTTP_FORBIDDEN);
        }

        $outcome = $this->seal->seal($session, $manifest, $user, $request->getContent());

        return $this->json($outcome->toArray(), $outcome->httpStatus());
    }

    #[Route('/api/lab/sessions/{recordingSessionId}/references', name: 'api_lab_evidence_references', methods: ['GET'])]
    #[OA\Get(
        path: '/api/lab/sessions/{recordingSessionId}/references',
        summary: 'Seal and reference-receipt status of one recording (IP-162)',
        description: 'Every seal attempt with its disposition and every sweep receipt with its reconciliation status. '
            . 'Owner or superadmin. Carries no radio estimate and no model output.',
        tags: ['Lab'],
    )]
    #[OA\Response(response: 200, description: 'seal attempts and receipts')]
    #[OA\Response(response: 403, description: 'not the owner of this recording')]
    #[OA\Response(response: 404, description: 'unknown recording')]
    public function references(string $recordingSessionId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $id = LabSessionRegister::sanitize($recordingSessionId);
        $session = $this->sessions->find($id);
        if (!$session instanceof LabSession) {
            return $this->json(['error' => 'Unknown recording'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->mayRead($session, $user)) {
            return $this->json(['error' => 'This recording does not belong to you'], Response::HTTP_FORBIDDEN);
        }

        $accepted = $this->manifests->findAccepted($id);

        return $this->json([
            'recording_session_id' => $id,
            'complete' => $session->isComplete(),
            'sealed' => $accepted !== null,
            'accepted_manifest_sha256' => $accepted?->getManifestSha256(),
            'seal_attempts' => array_map(static fn ($m) => $m->toReceipt(), $this->manifests->findForRecording($id)),
            'references' => array_map(static fn ($r) => $r->toReceipt(), $this->receipts->findForRecording($id)),
        ]);
    }

    private function mayRead(LabSession $session, User $user): bool
    {
        if ($this->isGranted('ROLE_SUPERADMIN')) {
            return true;
        }
        $owner = $session->getUser()?->getId()?->toRfc4122();

        return $owner !== null && $owner === $user->getId()?->toRfc4122();
    }
}
