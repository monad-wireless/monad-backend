<?php

namespace App\Controller;

use App\Constants\ErrorCode;
use App\Dto\Lab\GroundTruthBatch;
use App\Entity\User;
use App\Exception\AuthException;
use App\Exception\ValidationException;
use App\Service\GroundTruthService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The ground-truth (people) channel.
 *
 * `POST /api/lab/ground-truth` collects what every participant's handset scanned;
 * `GET /api/lab/ground-truth/{labSessionId}` gives it back as one room-wide tally.
 *
 * The pair exists because the console's own count is structurally useless as a room count: each
 * participant scans on their own phone, so a handset knows about one person. The operator needs the
 * number for the room, and the room only exists on the server.
 *
 * **Privacy posture — count without identify.** Everything that crosses this surface is keyed by
 * `participant_token`, an opaque pseudonym minted on the handset. No name, no e-mail, no MAC and
 * no device identifier is accepted, stored, or returned; there is deliberately no join from a token
 * to a `User`. The endpoint learns that *someone* entered `ZONE-A`. It never learns who.
 */
class GroundTruthController extends AbstractController
{
    public function __construct(
        private GroundTruthService $groundTruth,
    ) {
    }

    #[Route('/api/lab/ground-truth', name: 'api_lab_ground_truth_ingest', methods: ['POST'])]
    #[OA\Post(
        path: '/api/lab/ground-truth',
        summary: 'Submit ground-truth check-in/out scans (single or batched)',
        description: <<<'TXT'
        Accepts one scan or a batch of them from a participant device. Idempotent on `scan_nonce`:
        a handset re-uploads its complete set on every flush, so the same nonce arrives many times
        and re-sending is free.

        Field names are the pre-registered `ground_truth.tsv` column names, verbatim
        (`mono_ns, wall_ms, lab_session_id, participant_token, zone_id, direction, site, scan_nonce,
        recording_session_id`), so one spelling runs from the printed code through to the analysis
        join. `direction` must already be resolved to `in` or `out` — the printed code may be a
        toggle, but toggles are resolved on the handset against that participant's own history.

        Body is either `{"events": [...]}` or a single bare event object.

        Per-event outcomes, never all-or-nothing — one malformed row must not cost the other forty:
        - `accepted`  — stored for the first time
        - `duplicate` — same nonce, same `(participant_token, zone_id, direction)`; the earliest
                        `mono_ns` is kept
        - `conflict`  — same nonce, **different** triple. The stored row is left untouched and the
                        contradiction is recorded. This is pre-registration exclusion E3: the scan
                        is unresolved, the affected interval leaves every test, and the conflict is
                        surfaced in the aggregate so the operator sees it while the session is still
                        running. Contradictions are logged, never reconciled by judgement.
        - `rejected`  — unparseable row; the rest of the batch still lands

        Carries only pseudonymous participant tokens. Never send a name, an e-mail, a MAC or a
        device identifier.
        TXT,
        security: [['Bearer' => []]],
        tags: ['Lab']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'events',
                    type: 'array',
                    items: new OA\Items(
                        required: [
                            'lab_session_id', 'participant_token', 'zone_id',
                            'direction', 'mono_ns', 'wall_ms', 'scan_nonce',
                        ],
                        properties: [
                            new OA\Property(property: 'lab_session_id', type: 'string', example: '0198f2c1-1f3f-7c3a-9a1d-2f2b0a5f2f11'),
                            new OA\Property(property: 'participant_token', type: 'string', example: 'p-7f3a9c', description: 'Opaque pseudonym. Never a name or an e-mail.'),
                            new OA\Property(property: 'zone_id', type: 'string', example: 'ZONE-A'),
                            new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out'], example: 'in'),
                            new OA\Property(property: 'site', type: 'string', example: 'fiit-library'),
                            new OA\Property(property: 'mono_ns', type: 'integer', format: 'int64', example: 843_221_004_991_233),
                            new OA\Property(property: 'wall_ms', type: 'integer', format: 'int64', example: 1_754_640_000_000),
                            new OA\Property(property: 'scan_nonce', type: 'string', example: '2f9a4c11-6b0e-4d5a-9c17-8a0b6d2e4f31'),
                            new OA\Property(property: 'recording_session_id', type: 'string', nullable: true, example: null),
                        ],
                        type: 'object'
                    )
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Batch recorded; per-event outcomes returned',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'accepted', type: 'integer', example: 3),
                new OA\Property(property: 'duplicates', type: 'integer', example: 12),
                new OA\Property(property: 'conflicts', type: 'integer', example: 0),
                new OA\Property(property: 'rejected', type: 'integer', example: 0),
                new OA\Property(
                    property: 'results',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'scan_nonce', type: 'string'),
                            new OA\Property(property: 'status', type: 'string', enum: ['accepted', 'duplicate', 'conflict', 'rejected']),
                            new OA\Property(property: 'reason', type: 'string', nullable: true),
                        ],
                        type: 'object'
                    )
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Body is not an event or batch, is empty, or is oversized')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function ingest(Request $request): JsonResponse
    {
        if (!$this->getUser() instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        $batch = GroundTruthBatch::fromPayload(json_decode($request->getContent(), true));

        $ingested = $batch->events === []
            ? ['results' => [], 'counts' => [
                GroundTruthService::OUTCOME_ACCEPTED => 0,
                GroundTruthService::OUTCOME_DUPLICATE => 0,
                GroundTruthService::OUTCOME_CONFLICT => 0,
            ]]
            : $this->groundTruth->ingest($batch->events);

        return $this->json([
            'accepted' => $ingested['counts'][GroundTruthService::OUTCOME_ACCEPTED],
            'duplicates' => $ingested['counts'][GroundTruthService::OUTCOME_DUPLICATE],
            'conflicts' => $ingested['counts'][GroundTruthService::OUTCOME_CONFLICT],
            'rejected' => count($batch->rejected),
            'results' => array_merge($ingested['results'], $batch->rejected),
        ], Response::HTTP_OK);
    }

    #[Route(
        '/api/lab/ground-truth/{labSessionId}',
        name: 'api_lab_ground_truth_aggregate',
        requirements: ['labSessionId' => '[^/]+'],
        methods: ['GET']
    )]
    #[OA\Get(
        path: '/api/lab/ground-truth/{labSessionId}',
        summary: 'Live room-wide ground-truth tally for one lab session',
        description: <<<'TXT'
        The number the operator actually needs: how many **people** are checked in right now, per
        zone and for the room, aggregated across every participant's handset. Cheap enough to poll
        every few seconds.

        Occupancy rule: per participant, **latest scan wins**. The pre-registration defines the
        level as the cumulative sum of resolved directions over `(lab_session_id, zone_id)`, which
        is the same number whenever directions alternate — which the handset guarantees, because it
        resolves toggle codes against that participant's own history. Latest-wins is additionally
        idempotent under a double tap, so both are reported:

        - `checked_in` — distinct participants whose latest scan in that zone was `in`
        - `net_sum`    — the literal cumulative sum

        They disagree only when somebody scanned the same direction twice in one zone, and that
        disagreement is a live data-quality signal rather than something to round away.

        Likewise at room level, `overall.checked_in` counts each participant once by their latest
        scan **anywhere** in the session — the number to compare against a head count — while
        `overall.zone_sum` adds the per-zone counts. The gap between them is exactly the set of
        people who entered a new zone without scanning out of the old one.

        `conflicts` carries E3 contradictions: nonces claimed twice with different
        `(participant_token, zone_id, direction)`. A non-empty list means the affected intervals
        leave every test, and it is shown during the session because that is the only moment a
        human can still walk over and find out what happened.

        Returns opaque participant tokens only — never names, e-mails or device identifiers.
        TXT,
        security: [['Bearer' => []]],
        tags: ['Lab']
    )]
    #[OA\Parameter(
        name: 'labSessionId',
        description: 'The lab session id carried by the printed check-in code',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'Live tally. An unknown session id is an empty tally, not a 404 — the operator polls before the first scan exists.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'lab_session_id', type: 'string'),
                new OA\Property(property: 'server_wall_ms', type: 'integer', format: 'int64'),
                new OA\Property(
                    property: 'overall',
                    properties: [
                        new OA\Property(property: 'checked_in', type: 'integer', example: 7),
                        new OA\Property(property: 'participant_tokens', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'zone_sum', type: 'integer', example: 7),
                        new OA\Property(property: 'event_count', type: 'integer', example: 34),
                        new OA\Property(property: 'zone_count', type: 'integer', example: 3),
                        new OA\Property(property: 'last_event_mono_ns', type: 'integer', format: 'int64', nullable: true),
                        new OA\Property(property: 'last_event_wall_ms', type: 'integer', format: 'int64', nullable: true),
                        new OA\Property(property: 'conflict_count', type: 'integer', example: 0),
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'zones',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'zone_id', type: 'string', example: 'ZONE-A'),
                            new OA\Property(property: 'checked_in', type: 'integer', example: 3),
                            new OA\Property(property: 'participant_tokens', type: 'array', items: new OA\Items(type: 'string')),
                            new OA\Property(property: 'net_sum', type: 'integer', example: 3),
                            new OA\Property(property: 'event_count', type: 'integer', example: 12),
                            new OA\Property(property: 'last_event_mono_ns', type: 'integer', format: 'int64', nullable: true),
                            new OA\Property(property: 'last_event_wall_ms', type: 'integer', format: 'int64', nullable: true),
                            new OA\Property(property: 'conflict_count', type: 'integer', example: 0),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'conflicts', type: 'array', items: new OA\Items(type: 'object')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function aggregate(string $labSessionId): JsonResponse
    {
        if (!$this->getUser() instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        $labSessionId = trim($labSessionId);
        if ($labSessionId === '') {
            throw new ValidationException(ErrorCode::LAB_SESSION_ID_REQUIRED);
        }

        return $this->json($this->groundTruth->aggregate($labSessionId), Response::HTTP_OK);
    }
}
