<?php

namespace App\Controller;

use App\Dto\Lab\ArmingMatrixResponseDto;
use App\Quest\ArmingMatrixBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * "What is armed where, and why not?" — the ops read behind `/quests/config` (IP-129 §4.2).
 *
 * The data already exists; the projection did not. `GET /api/quests` carries no window and no
 * arming, `GET /api/quest/{id}` carries no device dimension at all, and `GET /api/device/{slug}`
 * carries the availability but drops three of its reasons on the floor.
 *
 * The matrix itself is built by {@see ArmingMatrixBuilder}, which the admin's arming page reads
 * too (IP-149): one computation, two readers, no way for the site and the operator to disagree.
 *
 * UNAUTHENTICATED, like the two public reads either side of it. What that publishes is
 * experimental design — accepted by owner decision 4 — and two properties bound what it buys an
 * adversary: the answer key is private (`QuestStepDto`, closed 2026-08-14), and points are a
 * static float echoed on completion with no ledger behind them, so knowing the design does not
 * let anyone accumulate anything. The `security.yaml` entry is anchored (`^/api/lab/arming-matrix$`)
 * for the reason every entry in that block is: `/api/lab/*` is otherwise authenticated because it
 * carries AP credentials and accepts ground-truth writes, and only this one read is public.
 */
class ArmingMatrixController extends AbstractController
{
    public function __construct(
        private readonly ArmingMatrixBuilder $matrix,
    ) {
    }

    #[Route('/api/lab/arming-matrix', name: 'api_lab_arming_matrix', methods: ['GET'])]
    public function matrix(): JsonResponse
    {
        $rows = $this->matrix->build()['rows'];
        // The public projection carries what it carried before IP-149; the two admin-only
        // keys the builder adds for the operator's table stay out of the JSON.
        foreach ($rows as &$row) {
            unset($row['audience'], $row['produces_measurement']);
        }
        unset($row);

        $response = $this->json((new ArmingMatrixResponseDto($rows))->toArray());
        // The same short, uniform cache the other two public reads carry: the portal page in
        // front of this is the load-shedding layer.
        $response->headers->set('Cache-Control', 'public, max-age=30');

        return $response;
    }
}
