<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\BetaSignupRepository;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Participants — the rail entry, ahead of the lane that fills it (IP-157 Phase 10).
 *
 * This page is HONEST rather than empty. Phase 10 replaces it with one list over users and
 * signups together, filtered by the pipeline stage, and a person page with five tabs. Until
 * then the two surfaces that do the job still exist and this page names them and links to
 * them, with the two counts that say how much is on each. A menu entry that led nowhere, or a
 * page that pretended a list was coming, would both be worse than saying what is here.
 */
#[IsGranted('ROLE_SUPERADMIN')]
final class PeopleController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly BetaSignupRepository $signups,
        private readonly AdminUrlGenerator $adminUrls,
    ) {
    }

    #[AdminRoute(path: '/people', name: 'people')]
    public function index(): Response
    {
        $counts = $this->signups->countByStatus();

        return $this->render('admin/people.html.twig', [
            'accounts' => $this->users->count([]),
            'recent_accounts' => $this->users->findBy([], ['createdAt' => 'DESC'], 50),
            'signups_waiting' => ($counts['new'] ?? 0) + ($counts['invited'] ?? 0),
            'signups_total' => array_sum($counts),
            'users_url' => $this->adminUrls->setController(UserCrudController::class)->setAction('index')->generateUrl(),
        ]);
    }
}
