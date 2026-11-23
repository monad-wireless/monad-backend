<?php

namespace App\Controller\Admin;

use App\Entity\BetaSignup;
use App\Enum\BetaSignupStatus;
use App\Repository\BetaSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The signup row actions behind the people desk (IP-157 Phase 5, folded into /admin/people on
 * 2026-11-23).
 *
 * The desk page itself is gone: signups are rows of the one people list, and "Create account"
 * there (PeopleController) replaces the .eml-then-mark-invited routine. What stays here are the
 * side exits and the notes, plus the CSV export. Every write is a POST with a session CSRF
 * token and redirects back to the people list, so the back button never re-posts.
 */
#[IsGranted('ROLE_SUPERADMIN')]
final class OnboardingController extends AbstractController
{
    public const CSRF = 'onboarding';

    public function __construct(
        private readonly BetaSignupRepository $signups,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** The old desk address, kept so bookmarks land on the signups that still need an account. */
    #[AdminRoute(path: '/people/onboarding', name: 'people_onboarding')]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('admin_people', ['stage' => 'awaiting']);
    }

    /** Every signup as text/csv: what a spreadsheet or a mail merge needs and nothing more. */
    #[AdminRoute(path: '/people/onboarding/export.csv', name: 'people_onboarding_export')]
    public function export(): Response
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream.');
        }
        fputcsv($handle, ['email', 'name', 'platform', 'availability', 'updates_opt_in', 'status', 'created_at', 'invited_at', 'registered_at'], escape: '\\');
        foreach ($this->signups->findByStatuses([], 10_000) as $s) {
            fputcsv($handle, [
                $s->getEmail(),
                $s->getName() ?? '',
                $s->getPlatform()->value,
                $s->getAvailability()->value,
                $s->isUpdatesOptIn() ? '1' : '0',
                $s->getStatus()->value,
                $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
                $s->getInvitedAt()?->format(\DateTimeInterface::ATOM) ?? '',
                $s->getRegisteredAt()?->format(\DateTimeInterface::ATOM) ?? '',
            ], escape: '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="beta-signups-%s.csv"', (new \DateTimeImmutable())->format('Ymd')),
        ]);
    }

    #[AdminRoute(path: '/people/onboarding/{id}/decline', name: 'people_onboarding_decline', options: ['methods' => ['POST']])]
    public function decline(string $id, Request $request): RedirectResponse
    {
        $signup = $this->guarded($id, $request);
        $this->transition($signup, static fn (BetaSignup $s) => $s->markDeclined(), sprintf('%s declined. The applicant is told nothing.', $signup->getEmail()));

        return $this->back($request);
    }

    #[AdminRoute(path: '/people/onboarding/{id}/withdraw', name: 'people_onboarding_withdraw', options: ['methods' => ['POST']])]
    public function withdraw(string $id, Request $request): RedirectResponse
    {
        $signup = $this->guarded($id, $request);
        $this->transition($signup, static fn (BetaSignup $s) => $s->withdraw(), 'Signup withdrawn: email, name and notes scrubbed; the dates stay.');

        return $this->back($request);
    }

    #[AdminRoute(path: '/people/onboarding/{id}/notes', name: 'people_onboarding_notes', options: ['methods' => ['POST']])]
    public function notes(string $id, Request $request): RedirectResponse
    {
        $signup = $this->guarded($id, $request);
        if ($signup->getStatus() === BetaSignupStatus::WITHDRAWN) {
            $this->addFlash('warning', 'A withdrawn row keeps no notes.');

            return $this->back($request);
        }
        $notes = trim($request->request->getString('notes'));
        $signup->setNotes($notes === '' ? null : $notes);
        $this->em->flush();
        $this->addFlash('success', sprintf('Notes saved for %s.', $signup->getEmail()));

        return $this->back($request);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /** The row, after the CSRF token and the id have both been checked. */
    private function guarded(string $id, Request $request): BetaSignup
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Stale form: reload the page and try again.');
        }
        $signup = Uuid::isValid($id) ? $this->signups->find(Uuid::fromString($id)) : null;
        if (!$signup instanceof BetaSignup) {
            throw new NotFoundHttpException('No beta signup with that id.');
        }

        return $signup;
    }

    /** @param callable(BetaSignup): mixed $change */
    private function transition(BetaSignup $signup, callable $change, string $done): void
    {
        try {
            $change($signup);
        } catch (\LogicException $e) {
            // The entity refuses a transition its status does not allow (a double click, or two
            // tabs). Tell the operator which one and change nothing.
            $this->addFlash('warning', $e->getMessage());

            return;
        }
        $this->em->flush();
        $this->addFlash('success', $done);
    }

    /** Back to the people list, or to the person page when the form came from one. */
    private function back(Request $request): RedirectResponse
    {
        $person = $request->request->getString('person');
        if ($person !== '' && Uuid::isValid($person)) {
            return $this->redirectToRoute('admin_participant', ['id' => $person]);
        }
        $stage = $request->request->getString('stage');

        return $this->redirectToRoute('admin_people', $stage === '' ? [] : ['stage' => $stage]);
    }
}
