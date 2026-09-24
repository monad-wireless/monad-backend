<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\QuestEnrollmentRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What a participant has actually done (IP-145).
 *
 * Separate from `AuthController::me()` on purpose. That endpoint answers "who am I" and is
 * hit on every launch; this one aggregates and is hit when somebody opens a profile. Putting
 * the aggregate in `me()` would make the launch path pay for it.
 *
 * THREE BLOCKS, AND THE LAST IS THE ONE WORTH BUILDING.
 *
 * `points` and `history` are the game. `contribution` answers "what did my walking produce",
 * and it is the only half whose usefulness does not rest on gamification evidence we do not
 * have: searched 2026-09-01, the vault holds zero claims on gamification and three passing
 * mentions with no effect size. So the score is shipped as a measured intervention (EXP-012)
 * and the contribution block is shipped because it is true.
 *
 * Every number here comes from rows the backend already wrote. Nothing new is captured.
 */
class ProfileController extends AbstractController
{
    #[Route('/api/me/stats', name: 'api_me_stats', methods: ['GET'])]
    #[OA\Get(
        path: '/api/me/stats',
        summary: "The signed-in participant's totals, history and contribution",
        description: 'Aggregated from quest enrollments and step completions. Creates nothing.',
        security: [['Bearer' => []]],
        tags: ['User']
    )]
    #[OA\Response(
        response: 200,
        description: 'Stats retrieved',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'points_total', type: 'number', example: 360),
                new OA\Property(property: 'quests_completed', type: 'integer', example: 3),
                new OA\Property(property: 'contribution', type: 'object'),
                new OA\Property(property: 'history', type: 'array', items: new OA\Items(type: 'object')),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function stats(QuestEnrollmentRepository $enrollments): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($enrollments->statsForUser($user), Response::HTTP_OK);
    }
}
