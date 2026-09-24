<?php

namespace App\Controller;

use App\Dto\Quest\QuestCreateRequestDto;
use App\Dto\Quest\QuestCreateStepDto;
use App\Dto\Quest\QuestDetailResponseDto;
use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Entity\User;
use App\Enum\QuestStepType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use OpenApi\Attributes as OA;

#[Route('/api/admin')]
class AdminController extends AbstractController
{
    #[Route('/quests', name: 'api_admin_quests_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/admin/quests',
        summary: 'Create a new quest',
        description: 'Creates a new quest with steps. Requires ROLE_SUPERADMIN.',
        security: [['Bearer' => []]],
        tags: ['Admin - Quests']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'description', 'available_from', 'steps'],
            properties: [
                new OA\Property(
                    property: 'name',
                    type: 'string',
                    example: 'Campus Discovery Tour',
                    description: 'Quest name (max 255 characters)'
                ),
                new OA\Property(
                    property: 'description',
                    type: 'string',
                    example: 'Explore the main campus buildings and learn about university history',
                    description: 'Quest description'
                ),
                new OA\Property(
                    property: 'available_from',
                    type: 'string',
                    format: 'date-time',
                    example: '2025-01-01T00:00:00Z',
                    description: 'Quest becomes available from this date'
                ),
                new OA\Property(
                    property: 'available_to',
                    type: 'string',
                    format: 'date-time',
                    nullable: true,
                    example: '2025-12-31T23:59:59Z',
                    description: 'Quest expires at this date (optional)'
                ),
                new OA\Property(
                    property: 'points',
                    type: 'number',
                    format: 'float',
                    example: 100.0,
                    description: 'Points awarded for completing this quest'
                ),
                new OA\Property(
                    property: 'estimated_duration',
                    type: 'integer',
                    nullable: true,
                    example: 30,
                    description: 'Estimated duration in minutes'
                ),
                new OA\Property(
                    property: 'featured_image',
                    type: 'string',
                    nullable: true,
                    example: 'https://fsn1.your-objectstorage.com/monad-knowledge/public/quest-image.jpg',
                    description: 'Featured image URL (max 512 characters)'
                ),
                new OA\Property(
                    property: 'steps',
                    type: 'array',
                    items: new OA\Items(
                        required: ['name', 'type', 'order', 'config'],
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'Scan QR Code'),
                            new OA\Property(
                                property: 'type',
                                type: 'string',
                                enum: ['start', 'wait', 'scan_qr', 'connect_to_ap', 'walk_to', 'find_ble_device', 'sensor_capture', 'ble_advertise', 'finish']
                            ),
                            new OA\Property(property: 'order', type: 'integer', example: 0),
                            new OA\Property(
                                property: 'config',
                                type: 'object',
                                description: 'Step-specific configuration (varies by type)'
                            )
                        ],
                        type: 'object'
                    ),
                    description: 'Quest steps'
                )
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Quest created successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Quest created successfully'),
                new OA\Property(
                    property: 'quest',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string'),
                        new OA\Property(property: 'points', type: 'number'),
                        new OA\Property(property: 'estimatedDuration', type: 'integer', nullable: true),
                        new OA\Property(property: 'featuredImage', type: 'string', nullable: true),
                        new OA\Property(property: 'createdAt', type: 'string'),
                        new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Validation error',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Validation failed'),
                new OA\Property(property: 'details', type: 'object')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Authentication required')
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'Forbidden - requires ROLE_SUPERADMIN',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Access denied')
            ]
        )
    )]
    public function createQuest(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'error' => 'Authentication required'
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
        $requestDto = new QuestCreateRequestDto();
        $requestDto->name = $data['name'] ?? null;
        $requestDto->description = $data['description'] ?? null;
        $requestDto->available_from = $data['available_from'] ?? null;
        $requestDto->available_to = $data['available_to'] ?? null;
        $requestDto->points = isset($data['points']) ? (float)$data['points'] : 0.0;
        $requestDto->estimated_duration = $data['estimated_duration'] ?? null;
        $requestDto->featured_image = $data['featured_image'] ?? null;
        $requestDto->steps = [];

        // Map steps
        if (isset($data['steps']) && is_array($data['steps'])) {
            foreach ($data['steps'] as $stepData) {
                $stepDto = new QuestCreateStepDto();
                $stepDto->name = $stepData['name'] ?? null;
                $stepDto->type = $stepData['type'] ?? null;
                $stepDto->order = $stepData['order'] ?? null;
                $stepDto->config = $stepData['config'] ?? [];
                $requestDto->steps[] = $stepDto;
            }
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
            // Create quest entity
            $quest = new Quest();
            $quest->setName($requestDto->name);
            $quest->setDescription($requestDto->description);
            $quest->setAvailableFrom(new \DateTime($requestDto->available_from));

            if ($requestDto->available_to) {
                $quest->setAvailableTo(new \DateTime($requestDto->available_to));
            }

            $quest->setPoints($requestDto->points);
            $quest->setEstimatedDuration($requestDto->estimated_duration);
            $quest->setFeaturedImage($requestDto->featured_image);
            $quest->setCreatedBy($user);

            $entityManager->persist($quest);

            // Create quest steps
            foreach ($requestDto->steps as $stepDto) {
                $step = new QuestStep();
                $step->setName($stepDto->name);
                $step->setType(QuestStepType::from($stepDto->type));
                $step->setOrder($stepDto->order);
                $step->setConfig($stepDto->config);
                $step->setQuest($quest);

                $quest->addStep($step);
                $entityManager->persist($step);
            }

            // Flush and commit
            $entityManager->flush();
            $entityManager->commit();

            // Build response. Step config is echoed back here on purpose: this is
            // ^/api/admin (ROLE_SUPERADMIN, tailnet-only) and the author needs to
            // see the config they just posted. The default is off because the
            // public detail route shares this DTO.
            $responseDto = QuestDetailResponseDto::fromEntity($quest, includeStepConfig: true);

            return $this->json([
                'message' => 'Quest created successfully',
                'quest' => $responseDto->toArray()
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            $entityManager->rollback();
            return $this->json([
                'error' => 'Failed to create quest',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
