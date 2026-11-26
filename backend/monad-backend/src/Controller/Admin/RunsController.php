<?php

namespace App\Controller\Admin;

use App\Entity\LabSession;
use App\Entity\QuestEnrollment;
use App\Repository\LabSessionRepository;
use App\Repository\QuestEnrollmentRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Runs — the rail entry, ahead of the lane that fills it (IP-157 Phase 9).
 *
 * Nine menu entries used to live here, seven of them a raw table of one entity each. They are
 * gone from the menu and every one of them is still routable; this page is the index that
 * keeps them reachable, and it says which are reading pages and which are raw registers, so
 * nobody mistakes a table dump for a designed surface.
 *
 * Phase 9 makes the ENROLLMENT the record — one list with filters, one run page that folds in
 * its recording sessions, handset snapshot, scans and skips, and a Data quality tab. The two
 * numbers below are the ones that will head that list.
 */
#[IsGranted('ROLE_SUPERADMIN')]
final class RunsController extends AbstractController
{
    public function __construct(
        private readonly QuestEnrollmentRepository $enrollments,
        private readonly LabSessionRepository $sessions,
        private readonly AdminUrlGenerator $adminUrls,
    ) {
    }

    #[AdminRoute(path: '/runs', name: 'runs')]
    public function index(): Response
    {
        return $this->render('admin/runs.html.twig', [
            'recent' => $this->enrollments->findRecent(100),
            'runs_total' => $this->enrollments->count([]),
            'sessions_total' => $this->sessions->count([]),
            'enrollments_url' => $this->crudUrl(QuestEnrollment::class),
            'sessions_url' => $this->crudUrl(LabSession::class),
        ]);
    }

    private function crudUrl(string $entityFqcn): string
    {
        return $this->adminUrls->setController(match ($entityFqcn) {
            QuestEnrollment::class => QuestEnrollmentCrudController::class,
            LabSession::class => LabSessionCrudController::class,
        })->setAction('index')->generateUrl();
    }
}
