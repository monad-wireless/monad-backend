<?php

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Fleet\FleetMetricsReader;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lab: what the instrument IS, as opposed to what it recorded (IP-157 Phase 8).
 *
 * The rail entry lands here, and here shows the Fleet tab — the vitals, because "is the fleet
 * hearing anything" is the question an operator opens this section with. The other tabs link
 * to the pages that already exist (arming matrix, device register, placement board, bundle);
 * Phase 11 folds them into this page properly. Nothing is unreachable meanwhile, which is the
 * whole point of a tab bar that links out rather than a menu that was deleted.
 *
 * The vitals come from FleetMetricsReader, the same closed PromQL allow-list the public
 * /api/lab/fleet answers from, and `reachable: false` stays a sentence: a metrics store that
 * cannot be read is a different fact from a resting fleet and must never be drawn as zeros.
 */
#[IsGranted('ROLE_SUPERADMIN')]
final class LabController extends AbstractController
{
    public function __construct(
        private readonly FleetMetricsReader $fleet,
    ) {
    }

    #[AdminRoute(path: '/lab', name: 'lab')]
    public function index(): Response
    {
        return $this->render('admin/lab.html.twig', [
            'fleet' => $this->fleet->snapshot(),
        ]);
    }
}
