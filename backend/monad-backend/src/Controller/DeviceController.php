<?php

namespace App\Controller;

use App\Dto\Device\DevicePublicResponseDto;
use App\Entity\Quest;
use App\Quest\QuestArmingService;
use App\Repository\DeviceRepository;
use App\Repository\QuestEnrollmentRepository;
use App\Repository\QuestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * "What is this box, and what can I do here?" — the one canonical answer (IP-128).
 *
 * Consumed by two callers that must never disagree: the public `/d/<slug>` page
 * on the portal (over loopback) and the app after a scanned label. One endpoint
 * means a quest cannot look available on the page and 409 on tap.
 *
 * UNAUTHENTICATED, and deliberately narrow because of it. Guests are read-only
 * by decision: they see what the node is and what its quests ARE, never
 * per-participant state. The `security.yaml` entry is anchored
 * (`^/api/device/[^/]+$`) so any future `/api/device/{slug}/…` write route stays
 * behind `IS_AUTHENTICATED_FULLY` by default rather than inheriting this hole.
 */
class DeviceController extends AbstractController
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly QuestRepository $quests,
        private readonly QuestArmingService $arming,
        private readonly QuestEnrollmentRepository $enrollments,
    ) {
    }

    #[Route('/api/device/{slug}', name: 'api_device_detail', methods: ['GET'])]
    public function detail(string $slug): JsonResponse
    {
        // Validate the shape before touching the database: a scan of a damaged or
        // forged code should cost a regex, not a query.
        if (1 !== preg_match('/^[a-z0-9-]{1,32}$/', $slug)) {
            return $this->json(['error' => 'Unknown device'], Response::HTTP_NOT_FOUND);
        }

        $device = $this->devices->findBySlug($slug);
        if (null === $device) {
            return $this->json(['error' => 'Unknown device'], Response::HTTP_NOT_FOUND);
        }

        $quests = [];
        foreach ($this->quests->findAll() as $quest) {
            if (!$quest instanceof Quest) {
                continue;
            }

            // No user: this is the public view, so availability reflects the
            // world (window, arming, node state), never a participant's history.
            $availability = $this->arming->assess(
                quest: $quest,
                user: null,
                device: $device,
                requiresCapture: QuestArmingService::producesMeasurement($quest),
            );

            if (QuestAvailabilityFilter::isHidden($availability->reason)) {
                continue;
            }

            $quests[] = [
                'id' => (string) $quest->getId(),
                'name' => $quest->getName(),
                'description' => $quest->getDescription(),
                'points' => $quest->getPoints(),
                'estimated_duration' => $quest->getEstimatedDuration(),
                'required_capabilities' => $quest->getRequiredCapabilities(),
                'recurrence' => $quest->getRecurrence(),
                'availability' => $availability->jsonSerialize(),
            ];
        }

        $dto = new DevicePublicResponseDto(
            $device,
            $quests,
            $this->devices->countActive(),
            $this->enrollments->countDistinctParticipantsAtDevice($device),
        );

        $response = $this->json($dto->toArray());
        // Short, uniform cache: the page behind this is the load-shedding layer,
        // and forty simultaneous scans in one lecture must collapse to one read.
        $response->headers->set('Cache-Control', 'public, max-age=30');

        return $response;
    }
}

/**
 * Which unavailable quests are hidden from the public list rather than shown
 * greyed out.
 *
 * A quest outside its window or not offered here is noise to a stranger; one on
 * cooldown or blocked by an idle node is *interesting* — it tells them to come
 * back, which is the behaviour the fleet wants.
 */
final class QuestAvailabilityFilter
{
    public static function isHidden(?string $reason): bool
    {
        return \in_array($reason, ['window_closed', 'not_armed', 'device_inactive'], true);
    }
}
