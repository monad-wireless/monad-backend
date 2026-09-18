<?php

namespace App\Controller;

use App\Dto\Quest\QuestCompleteDataFileDto;
use App\Dto\Quest\QuestCompleteRequestDto;
use App\Dto\Quest\QuestCompleteResponseDto;
use App\Dto\Quest\QuestCompleteSkipRecordDto;
use App\Dto\Quest\QuestCompleteStepDto;
use App\Dto\Quest\QuestDetailResponseDto;
use App\Dto\Quest\QuestListResponseDto;
use App\Dto\Quest\QuestStartQuestDto;
use App\Dto\Quest\QuestStartResponseDto;
use App\Dto\Quest\QuestStartStepDto;
use App\Entity\QuestEnrollment;
use App\Entity\QuestStepCompletion;
use App\Entity\QuestStepSkipRecord;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use App\Enum\QuestStepCompletionStatus;
use App\Quest\HandsetDescriptor;
use App\Quest\HandsetRegistry;
use App\Quest\QuestArmingService;
use App\Quest\RealisedRoute;
use App\Quest\QuestAvailability;
use App\Repository\DeviceRepository;
use App\Repository\QuestEnrollmentRepository;
use App\Repository\QuestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use OpenApi\Attributes as OA;

class QuestController extends AbstractController
{
    #[Route('/api/quests', name: 'api_quests_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/quests',
        summary: 'Get list of quests',
        description: 'Returns a list of quests filtered by status. By default returns active quests (available_from < now < available_to).',
        tags: ['Quests']
    )]
    #[OA\Parameter(
        name: 'status',
        in: 'query',
        description: 'Filter quests by status: active (default) or expired',
        required: false,
        schema: new OA\Schema(
            type: 'string',
            enum: ['active', 'expired'],
            default: 'active'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'List of quests retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'quests',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '60000a54-e220-4b17-95c3-ebdfa164caf9', description: 'Quest unique identifier'),
                            new OA\Property(property: 'name', type: 'string', example: 'Campus Discovery Tour', description: 'Quest name'),
                            new OA\Property(property: 'description', type: 'string', example: 'Explore the main campus buildings and learn about university history', description: 'Quest description'),
                            new OA\Property(property: 'points', type: 'number', format: 'float', example: 100.0, description: 'Points awarded for completing this quest'),
                            new OA\Property(property: 'estimatedDuration', type: 'integer', nullable: true, example: 30, description: 'Estimated duration in minutes'),
                            new OA\Property(property: 'numberOfSteps', type: 'integer', example: 5, description: 'Number of steps in this quest'),
                            new OA\Property(property: 'audience', type: 'string', enum: ['public', 'operator'], example: 'public', description: 'Who this quest is for. Only a superadmin ever receives an `operator` row.')
                        ],
                        type: 'object'
                    )
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - invalid status parameter',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Invalid status parameter. Allowed values: active, expired')
            ]
        )
    )]
    public function getQuests(
        Request $request,
        QuestRepository $questRepository
    ): JsonResponse {
        $status = $request->query->get('status', 'active');

        // Validate status parameter
        if (!in_array($status, ['active', 'expired'])) {
            return $this->json([
                'error' => 'Invalid status parameter. Allowed values: active, expired'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Fetch quests based on status
        try {
            if ($status === 'expired') {
                $quests = $questRepository->findExpiredQuests();
            } else {
                $quests = $questRepository->findActiveQuests();
            }

            // Audience filtering (IP-145). An operator quest is withheld from the listing
            // rather than refused at start with a reason: a reason would tell a participant
            // that a withheld quest exists, and filtering leaks nothing. `startQuest()`
            // checks the same thing separately, because a filtered list is a convenience and
            // never the authorisation.
            //
            // Placed here, above the capability filter, so it covers BOTH branches above and
            // any branch added later. It reuses ROLE_SUPERADMIN, which already gates /mcp:
            // that couples "may walk an operator take" to "may administer the lab", so an
            // operator take cannot currently be delegated to a student helper.
            if (!$this->isGranted('ROLE_SUPERADMIN')) {
                $quests = array_values(array_filter(
                    $quests,
                    static fn ($quest) => !$quest->isOperatorOnly()
                ));
            }

            // Capability filtering. A device sends what it can do; a quest that needs more is
            // withheld rather than offered and failed halfway through. Sending nothing keeps the
            // old behaviour (everything is offered), so existing clients are unaffected.
            $declared = $request->query->get('capabilities');
            if (is_string($declared) && '' !== trim($declared)) {
                $deviceCapabilities = array_values(array_filter(array_map(
                    'trim',
                    explode(',', $declared)
                )));
                $quests = array_values(array_filter(
                    $quests,
                    static fn ($quest) => $quest->isSupportedBy($deviceCapabilities)
                ));
            }

            // Transform entities to DTOs
            $questDtos = array_map(
                fn($quest) => (new QuestListResponseDto($quest))->toArray(),
                $quests
            );

            return $this->json([
                'quests' => $questDtos
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to retrieve quests',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/quest/{id}', name: 'api_quest_detail', methods: ['GET'])]
    #[OA\Get(
        path: '/api/quest/{id}',
        summary: 'Get quest detail',
        description: 'Returns full quest details including all steps. Does not require authentication.',
        tags: ['Quest']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'Quest UUID',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '60000a54-e220-4b17-95c3-ebdfa164caf9')
    )]
    #[OA\Response(
        response: 200,
        description: 'Quest details retrieved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '60000a54-e220-4b17-95c3-ebdfa164caf9', description: 'Quest unique identifier'),
                new OA\Property(property: 'name', type: 'string', example: 'Campus Discovery', description: 'Quest name'),
                new OA\Property(property: 'description', type: 'string', example: 'Explore the campus and discover hidden locations', description: 'Quest description'),
                new OA\Property(property: 'points', type: 'number', format: 'float', example: 100.0, description: 'Points awarded for completion'),
                new OA\Property(property: 'estimatedDuration', type: 'integer', example: 30, nullable: true, description: 'Estimated duration in minutes'),
                new OA\Property(property: 'featuredImage', type: 'string', example: 'https://fsn1.your-objectstorage.com/monad-knowledge/public/quest-image.jpg', nullable: true, description: 'Featured image URL'),
                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', example: '2025-11-11 15:28:44', description: 'Quest creation timestamp'),
                new OA\Property(
                    property: 'steps',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '70000a54-e220-4b17-95c3-ebdfa164caf9', description: 'Step unique identifier'),
                            new OA\Property(property: 'name', type: 'string', example: 'Scan QR Code at Library', description: 'Step name'),
                            new OA\Property(property: 'type', type: 'string', enum: ['start', 'wait', 'scan_qr', 'connect_to_ap', 'walk_to', 'find_ble_device', 'sensor_capture', 'ble_advertise', 'probe', 'observe', 'finish'], example: 'scan_qr', description: 'Step type'),
                            new OA\Property(property: 'order', type: 'integer', example: 1, description: 'Step order in quest sequence'),
                            new OA\Property(property: 'config', type: 'object', example: ['qr_code_id' => 'abc123'], description: 'Step-specific configuration')
                        ],
                        type: 'object'
                    ),
                    description: 'Quest steps ordered by sequence'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Quest not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Quest not found')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Invalid UUID format',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Invalid quest ID format')
            ]
        )
    )]
    public function getQuestDetail(
        string $id,
        QuestRepository $questRepository
    ): JsonResponse {
        // Validate UUID format
        if (!Uuid::isValid($id)) {
            return $this->json([
                'error' => 'Invalid quest ID format'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Convert string to Uuid object
        $uuid = Uuid::fromString($id);

        // Find quest with steps (eager loading)
        $quest = $questRepository->findOneBy(['id' => $uuid]);

        if (!$quest) {
            return $this->json([
                'error' => 'Quest not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Step config is withheld from anonymous callers. The route is
        // PUBLIC_ACCESS so a stranger can read what a quest asks of them, but
        // `config` holds `expected_value` for every scan_qr step — the answer key
        // to the people channel. The `api` firewall is stateless JWT, so a request
        // carrying a valid Bearer token populates getUser() while an anonymous one
        // is still served; that is the whole distinction.
        $dto = QuestDetailResponseDto::fromEntity($quest, includeStepConfig: $this->getUser() !== null);

        return $this->json($dto->toArray());
    }

    #[Route('/api/quest/{id}/start', name: 'api_quest_start', methods: ['POST'])]
    #[OA\Post(
        path: '/api/quest/{id}/start',
        summary: 'Start a quest',
        description: 'Creates a quest enrollment for the authenticated user and initializes all quest step completions. The optional body carries the handset descriptor (IP-149): what phone is walking this run, frozen on the enrollment as measurement provenance. An empty body is an app build that predates the descriptor and stays valid.',
        security: [['Bearer' => []]],
        tags: ['Quest']
    )]
    #[OA\RequestBody(
        required: false,
        description: 'Optional. `{"handset": {...}}` — the phone describing itself. Closed top-level keys: handset_id, platform (ios|android), machine, manufacturer, model, soc, os_version, os_build, app_version, build_id, capabilities (string list), sensors (list), radio (object), state (object). Unknown keys are rejected (400 VALIDATION_108); bodies over 64 kB are rejected (400 VALIDATION_109).',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'handset',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'handset_id', type: 'string', example: '0b6f2a8e-8c1d-4e2a-9f3b-1c2d3e4f5a6b'),
                        new OA\Property(property: 'platform', type: 'string', enum: ['ios', 'android']),
                        new OA\Property(property: 'machine', type: 'string', example: 'iPhone15,2'),
                        new OA\Property(property: 'manufacturer', type: 'string', example: 'Apple'),
                        new OA\Property(property: 'model', type: 'string', example: 'iPhone'),
                        new OA\Property(property: 'soc', type: 'string', nullable: true),
                        new OA\Property(property: 'os_version', type: 'string', example: '18.6'),
                        new OA\Property(property: 'os_build', type: 'string', example: '22G86'),
                        new OA\Property(property: 'app_version', type: 'string', example: '1.4.0'),
                        new OA\Property(property: 'build_id', type: 'string', example: '1.4.0+41.g9a1d2f2b'),
                        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'sensors', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'radio', type: 'object'),
                        new OA\Property(property: 'state', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'Quest UUID',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '60000a54-e220-4b17-95c3-ebdfa164caf9')
    )]
    #[OA\Response(
        response: 200,
        description: 'Quest started successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'enrollment_id', type: 'string', format: 'uuid', example: '70000b64-f330-5c27-a6d4-fceegb275db0'),
                new OA\Property(
                    property: 'quest',
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '60000a54-e220-4b17-95c3-ebdfa164caf9'),
                        new OA\Property(property: 'name', type: 'string', example: 'City Explorer Quest'),
                        new OA\Property(property: 'description', type: 'string', example: 'Explore the city and discover hidden gems'),
                        new OA\Property(
                            property: 'steps',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'step_id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'step_completion_id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'type', type: 'string', enum: ['start', 'wait', 'scan_qr', 'connect_to_ap', 'walk_to', 'find_ble_device', 'sensor_capture', 'ble_advertise', 'probe', 'observe', 'finish']),
                                    new OA\Property(property: 'order', type: 'integer'),
                                    new OA\Property(property: 'config', type: 'object')
                                ],
                                type: 'object'
                            )
                        )
                    ],
                    type: 'object'
                ),
                new OA\Property(property: 'data_path', type: 'string', example: 's3://monad-bucket/experiments/2025/11/11/60000a54-e220-4b17-95c3-ebdfa164caf9/70000b64-f330-5c27-a6d4-fceegb275db0/'),
                new OA\Property(property: 'started_at', type: 'string', format: 'date-time', example: '2025-11-11T15:28:44Z')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - quest is not active or invalid',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Quest is not currently active')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized - not authenticated',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Authentication required')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Quest not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Quest not found')
            ]
        )
    )]
    public function startQuest(
        string $id,
        Request $request,
        QuestRepository $questRepository,
        DeviceRepository $deviceRepository,
        QuestEnrollmentRepository $enrollmentRepository,
        QuestArmingService $arming,
        HandsetRegistry $handsets,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        // Check authentication
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'error' => 'Authentication required'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // IP-149 — the phone describing itself. Parsed BEFORE any database work so a
        // malformed body costs a 400 and not a half-written enrollment. Null is an app
        // build that sent no body, and that stays valid; the descriptor's own validator
        // raises the 400 (VALIDATION_108 / _109) for anything present and wrong.
        $handsetDescriptor = HandsetDescriptor::fromRequestBody($request->getContent());

        // Validate UUID format
        try {
            $questId = Uuid::fromString($id);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => 'Invalid quest ID format'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find quest
        $quest = $questRepository->find($questId);
        if (!$quest) {
            return $this->json([
                'error' => 'Quest not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Audience gate (IP-145). The listing already hides this quest, but a filtered
        // list is a convenience and never the authorisation: the id is guessable and the
        // endpoint is reachable directly.
        //
        // 404, not 403. The whole reason the listing filters rather than returning a
        // QuestAvailability reason is that a reason discloses a withheld quest exists;
        // a 403 here would give that away again through the back door. To someone
        // without the role, an operator quest simply is not there.
        if ($quest->isOperatorOnly() && !$this->isGranted('ROLE_SUPERADMIN')) {
            return $this->json([
                'error' => 'Quest not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Validate quest is active
        $now = new \DateTime();
        $availableFrom = $quest->getAvailableFrom();
        $availableTo = $quest->getAvailableTo();

        if ($availableFrom && $availableFrom > $now) {
            return $this->json([
                'error' => 'Quest is not yet available'
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($availableTo && $availableTo < $now) {
            return $this->json([
                'error' => 'Quest is no longer available'
            ], Response::HTTP_BAD_REQUEST);
        }

        // IP-128 — which node is this run happening at? Optional: a quest started
        // from the catalogue rather than from a scanned label has no device, and
        // that stays valid.
        $device = null;
        $deviceSlug = $request->query->get('device');
        if (is_string($deviceSlug) && '' !== $deviceSlug) {
            $device = $deviceRepository->findBySlug($deviceSlug);
            if (null === $device) {
                return $this->json([
                    'error' => 'Unknown device'
                ], Response::HTTP_NOT_FOUND);
            }
        }

        // IP-128 — one gate, shared with the public device page, so a quest can
        // never look available there and 409 here.
        $availability = $arming->assess(
            quest: $quest,
            user: $user,
            device: $device,
            requiresCapture: false,
        );

        if (!$availability->available) {
            // A stale IN_PROGRESS run is not a refusal, it is litter: nothing
            // sets ABANDONED automatically, so a force-quit would otherwise
            // exclude this participant from this node permanently. Reap and let
            // the new run proceed.
            $reaped = false;
            if (QuestAvailability::REASON_IN_PROGRESS === $availability->reason) {
                $open = $enrollmentRepository->findOpenFor($user, $quest, $device);
                if (null !== $open && $arming->isStale($open, $quest)) {
                    $open->setStatus(QuestEnrollmentStatus::ABANDONED);
                    $entityManager->flush();
                    $reaped = true;
                }
            }

            if (!$reaped) {
                return $this->json([
                    'error' => 'Quest is not available right now',
                    'reason' => $availability->reason,
                    'retry_at' => $availability->retryAt?->format(\DateTimeInterface::ATOM),
                ], Response::HTTP_CONFLICT);
            }
        }

        // Create quest enrollment
        $enrollment = new QuestEnrollment();
        $enrollment->setUser($user);
        $enrollment->setQuest($quest);
        $enrollment->setDevice($device);
        $enrollment->setCompletedAt(null);

        // IP-149 — freeze the transmitter beside the receiver. The handset row is
        // found-or-created in the same unit of work as the enrollment, so a failure
        // after this point leaves neither. The snapshot is the body as received.
        if ($handsetDescriptor !== null) {
            $enrollment->attachHandset($handsets->observe($handsetDescriptor), $handsetDescriptor->toArray());
        }

        // Realise the route, once, here (IP-145). A quest with a pool gives a different
        // order to each enrollment; one without gives null and the declared step order is
        // served, which is every quest before IP-145.
        //
        // Server-side because two devices must not disagree about what was asked, and
        // because the analysis has to be able to recover what THIS walker was told to do.
        // The chosen sequence is stored rather than the seed that produced it: a seed only
        // reproduces against a frozen generator, and `loop_order` is not frozen.
        //
        // Nothing here computes geometry. The pool was generated by
        // `monad-knowledge lab quest-build`, which owns the rule that keeps a leg out of a
        // wall, and this picks an element of a list.
        $enrollment->setRealisedSteps($quest->drawRoute());

        // Create data path: s3://monad-bucket/experiments/YYYY/MM/DD/:user_id/:quest_id/:enrollment_id/
        $date = new \DateTime();
        $dataPath = sprintf(
            's3://monad-bucket/experiments/%s/%s/%s/%s/%s/%s/',
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            (string) $user->getId(),
            (string) $quest->getId(),
            (string) $enrollment->getId()
        );
        $enrollment->setDataPath($dataPath);

        // The steps THIS enrollment walks (IP-145). For a quest without a pool that is every
        // declared step in its declared order, which is every quest before IP-145.
        //
        // A completion row is created only for the steps actually served. Creating them for
        // the declared set would mean a pooled run could never reach 100%, and the fifteen
        // stops it was never asked to walk would sit unfinished forever.
        $steps = RealisedRoute::apply(
            $quest->getSteps()->toArray(),
            $enrollment->getRealisedSteps(),
        );
        $stepDtos = [];

        foreach ($steps as $index => $step) {
            $stepCompletion = new QuestStepCompletion();
            $stepCompletion->setEnrollment($enrollment);
            $stepCompletion->setStep($step);

            $enrollment->addStepCompletion($stepCompletion);
            $entityManager->persist($stepCompletion);

            // Renumbered to the realised sequence. The step ROWS are shared by every
            // enrollment and must keep their declared order; the sequence this walker was
            // asked for is a property of the response.
            $stepDtos[] = QuestStartStepDto::fromEntities($step, $stepCompletion, $index);
        }

        // Persist enrollment
        $entityManager->persist($enrollment);
        $entityManager->flush();

        // Build response
        $questDto = QuestStartQuestDto::fromEntity($quest, $stepDtos);
        $responseDto = QuestStartResponseDto::fromEntity($enrollment, $questDto);

        return $this->json($responseDto->toArray(), Response::HTTP_OK);
    }

    #[Route('/api/quest/{quest_id}/complete', name: 'api_quest_complete', methods: ['POST'])]
    #[OA\Post(
        path: '/api/quest/{quest_id}/complete',
        summary: 'Complete a quest',
        description: 'Receives bulk data from device after quest completion and updates enrollment and step completions',
        security: [['Bearer' => []]],
        tags: ['Quest']
    )]
    #[OA\Parameter(
        name: 'quest_id',
        description: 'Quest UUID',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', format: 'uuid')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['enrollment_id', 'completed_at', 'steps'],
            properties: [
                new OA\Property(
                    property: 'enrollment_id',
                    type: 'string',
                    format: 'uuid',
                    example: '60000a54-e220-4b17-95c3-ebdfa164caf9',
                    description: 'Quest enrollment UUID'
                ),
                new OA\Property(
                    property: 'completed_at',
                    type: 'string',
                    format: 'date-time',
                    example: '2025-11-11T15:30:00Z',
                    description: 'Quest completion timestamp'
                ),
                new OA\Property(
                    property: 'steps',
                    type: 'array',
                    items: new OA\Items(
                        required: ['step_completion_id', 'status', 'started_at', 'completed_at'],
                        properties: [
                            new OA\Property(property: 'step_completion_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'status', type: 'string', enum: ['completed', 'failed', 'skipped']),
                            new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'step_data', type: 'object', nullable: true),
                            new OA\Property(
                                property: 'skip_record',
                                nullable: true,
                                properties: [
                                    new OA\Property(property: 'message', type: 'string'),
                                    new OA\Property(property: 'error_code', type: 'string', nullable: true),
                                    new OA\Property(property: 'metadata', type: 'object', nullable: true)
                                ],
                                type: 'object'
                            )
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(
                    property: 'data_file',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'filename', type: 'string'),
                        new OA\Property(property: 'size', type: 'number'),
                        new OA\Property(property: 'checksum', type: 'string')
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Quest completed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'enrollment_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'points_earned', type: 'number', example: 100),
                new OA\Property(property: 'completed_at', type: 'string', format: 'date-time')
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Bad request - invalid data structure or validation errors',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string'),
                new OA\Property(property: 'details', type: 'object', nullable: true)
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden - enrollment does not belong to authenticated user',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string')
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Enrollment not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string')
            ]
        )
    )]
    #[OA\Response(
        response: 409,
        description: 'Conflict - enrollment already completed or abandoned',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string')
            ]
        )
    )]
    public function completeQuest(
        string $quest_id,
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'error' => 'Not authenticated'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Parse request body
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json([
                'error' => 'Invalid JSON data'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Map JSON data to DTO
        $requestDto = new QuestCompleteRequestDto();
        $requestDto->enrollment_id = $data['enrollment_id'] ?? null;
        $requestDto->completed_at = $data['completed_at'] ?? null;
        $requestDto->steps = [];

        // Map steps
        if (isset($data['steps']) && is_array($data['steps'])) {
            foreach ($data['steps'] as $stepData) {
                $stepDto = new QuestCompleteStepDto();
                $stepDto->step_completion_id = $stepData['step_completion_id'] ?? null;
                $stepDto->status = $stepData['status'] ?? null;
                $stepDto->started_at = $stepData['started_at'] ?? null;
                $stepDto->completed_at = $stepData['completed_at'] ?? null;
                // IP-128 — monotonic reading, so quest labels can be time-joined to
                // CSI despite RTC-less nodes and adjustable handset clocks.
                $stepDto->mono_ns = isset($stepData['mono_ns']) ? (string) $stepData['mono_ns'] : null;
                $stepDto->step_data = $stepData['step_data'] ?? [];

                // Map skip_record if present
                if (isset($stepData['skip_record']) && is_array($stepData['skip_record'])) {
                    $skipRecordDto = new QuestCompleteSkipRecordDto();
                    $skipRecordDto->message = $stepData['skip_record']['message'] ?? null;
                    $skipRecordDto->error_code = $stepData['skip_record']['error_code'] ?? null;
                    $skipRecordDto->metadata = $stepData['skip_record']['metadata'] ?? [];
                    $stepDto->skip_record = $skipRecordDto;
                }

                $requestDto->steps[] = $stepDto;
            }
        }

        // Map data_file if present
        if (isset($data['data_file']) && is_array($data['data_file'])) {
            $dataFileDto = new QuestCompleteDataFileDto();
            $dataFileDto->filename = $data['data_file']['filename'] ?? null;
            $dataFileDto->size = $data['data_file']['size'] ?? null;
            $dataFileDto->checksum = $data['data_file']['checksum'] ?? null;
            $requestDto->data_file = $dataFileDto;
        }

        // Validate DTO
        $errors = $validator->validate($requestDto);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json([
                'error' => 'Validation failed',
                'details' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        // Begin transaction
        $entityManager->beginTransaction();
        try {
            // 1. Verify enrollment exists
            $enrollmentUuid = Uuid::fromString($requestDto->enrollment_id);
            $enrollment = $entityManager->getRepository(QuestEnrollment::class)
                ->findOneBy(['id' => $enrollmentUuid]);

            if (!$enrollment) {
                $entityManager->rollback();
                return $this->json([
                    'error' => 'Enrollment not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // 2. Verify enrollment belongs to authenticated user
            if ($enrollment->getUser()->getId() != $user->getId()) {
                $entityManager->rollback();
                return $this->json([
                    'error' => 'This enrollment does not belong to you'
                ], Response::HTTP_FORBIDDEN);
            }

            // 3. Verify enrollment status is in_progress
            if ($enrollment->getStatus() !== QuestEnrollmentStatus::IN_PROGRESS) {
                $entityManager->rollback();
                return $this->json([
                    'error' => 'Enrollment is already ' . $enrollment->getStatus()->value
                ], Response::HTTP_CONFLICT);
            }

            // 4. Verify quest_id matches enrollment
            if ($enrollment->getQuest()->getId()->toString() !== $quest_id) {
                $entityManager->rollback();
                return $this->json([
                    'error' => 'Quest ID does not match enrollment'
                ], Response::HTTP_BAD_REQUEST);
            }

            // 5. Collect all step completion IDs from request
            $stepCompletionIds = array_map(
                fn($step) => Uuid::fromString($step->step_completion_id),
                $requestDto->steps
            );

            // 6. Fetch all step completions
            $stepCompletions = $entityManager->getRepository(QuestStepCompletion::class)
                ->findBy(['id' => $stepCompletionIds]);

            // 7. Verify all step_completion_ids exist
            if (count($stepCompletions) !== count($stepCompletionIds)) {
                $entityManager->rollback();
                return $this->json([
                    'error' => 'One or more step completion IDs are invalid'
                ], Response::HTTP_BAD_REQUEST);
            }

            // 8. Verify all step completions belong to this enrollment
            foreach ($stepCompletions as $stepCompletion) {
                if ($stepCompletion->getEnrollment()->getId() != $enrollment->getId()) {
                    $entityManager->rollback();
                    return $this->json([
                        'error' => 'Step completion does not belong to this enrollment'
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // 9. Create a map of step completions for easy lookup
            $stepCompletionMap = [];
            foreach ($stepCompletions as $stepCompletion) {
                $stepCompletionMap[$stepCompletion->getId()->toString()] = $stepCompletion;
            }

            // 10. Track if any critical step failed
            $hasFailedSteps = false;

            // 11. Update each step completion
            foreach ($requestDto->steps as $stepDto) {
                $stepCompletion = $stepCompletionMap[$stepDto->step_completion_id];

                // Map status
                $status = match($stepDto->status) {
                    'completed' => QuestStepCompletionStatus::COMPLETED,
                    'failed' => QuestStepCompletionStatus::FAILED,
                    'skipped' => QuestStepCompletionStatus::SKIPPED,
                };

                if ($status === QuestStepCompletionStatus::FAILED) {
                    $hasFailedSteps = true;
                }

                // Update step completion
                $stepCompletion->setStatus($status);
                $stepCompletion->setStartedAt(new \DateTime($stepDto->started_at));
                $stepCompletion->setCompletedAt(new \DateTime($stepDto->completed_at));
                // IP-128 — the monotonic pair for the wall clock above. Without it a
                // quest label cannot be placed against a CSI capture with confidence:
                // fleet nodes have no RTC and get stepped by chrony, and a handset's
                // wall clock is user-adjustable.
                $stepCompletion->setMonoNs($stepDto->mono_ns);
                $stepCompletion->setStepData($stepDto->step_data);

                // Create skip record if needed
                if (in_array($status, [QuestStepCompletionStatus::FAILED, QuestStepCompletionStatus::SKIPPED])) {
                    if ($stepDto->skip_record) {
                        $skipRecord = new QuestStepSkipRecord();
                        $skipRecord->setStepCompletion($stepCompletion);
                        $skipRecord->setMessage($stepDto->skip_record->message);
                        $skipRecord->setErrorCode($stepDto->skip_record->error_code);
                        $skipRecord->setMetadata($stepDto->skip_record->metadata);
                        $entityManager->persist($skipRecord);
                    }
                }

                $entityManager->persist($stepCompletion);
            }

            // 12. Update enrollment status
            if ($hasFailedSteps) {
                $enrollment->setStatus(QuestEnrollmentStatus::FAILED);
            } else {
                $enrollment->setStatus(QuestEnrollmentStatus::COMPLETED);

                // 12b. Freeze what this completion was worth (IP-145).
                //
                // Frozen here rather than derived later from `quests.points`, because that
                // column is mutable and a derived total silently rewrites history the first
                // time a quest is re-valued. Before IP-145 nothing accrued at all: the
                // response returned the quest's current value and stored none of it.
                //
                // Stamped with the SERVER clock, not `$requestDto->completed_at`, on the
                // same rule step 13 states: a participant-reported time may describe their
                // own step timings and may not be the record of when something was granted.
                //
                // `awardPoints()` is idempotent, so a replayed completion cannot double-pay
                // and cannot re-value an already finished walk.
                $enrollment->awardPoints($enrollment->getQuest()->getPoints(), new \DateTimeImmutable());
            }

            // 13. Set completed_at timestamp
            //
            // This value comes from the REQUEST BODY and is therefore participant
            // -reported, not observed. It is kept because the participant's own
            // clock is what their step timings are expressed in — but nothing
            // that gates access may be measured against it (IP-128).
            $enrollment->setCompletedAt(new \DateTime($requestDto->completed_at));

            // 13b. Stamp the SERVER's view of when this arrived.
            //
            // The recurrence cooldown reads only this column. Gating on
            // `completed_at` above would let a client post a backdated finish and
            // clear its own cooldown instantly, which is not a cooldown at all.
            $enrollment->markCompletionReceived();

            // 14. Update data_path if data_file provided
            if ($requestDto->data_file) {
                // Append filename to existing data_path
                $currentDataPath = $enrollment->getDataPath();
                if ($currentDataPath) {
                    $newDataPath = rtrim($currentDataPath, '/') . '/' . $requestDto->data_file->filename;
                    $enrollment->setDataPath($newDataPath);
                }
            }

            $entityManager->persist($enrollment);

            // 15. Commit transaction
            $entityManager->flush();
            $entityManager->commit();

            // 16. Prepare response
            //
            // `getPointsAwarded()` and not `getQuest()->getPoints()` (IP-145). The award was
            // frozen at step 15 from the quest's value as it then stood; reading the quest
            // again here would mean a re-valuation between the freeze and the response
            // changed what this completion was worth. The fallback keeps a pre-IP-145
            // enrollment answering rather than returning null.
            $response = new QuestCompleteResponseDto(
                success: true,
                enrollment_id: $enrollment->getId()->toString(),
                points_earned: $enrollment->getPointsAwarded() ?? $enrollment->getQuest()->getPoints(),
                completed_at: $enrollment->getCompletedAt()->format('Y-m-d\TH:i:s\Z')
            );

            return $this->json($response->toArray(), Response::HTTP_OK);

        } catch (\Exception $e) {
            $entityManager->rollback();
            return $this->json([
                'error' => 'Failed to complete quest',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
