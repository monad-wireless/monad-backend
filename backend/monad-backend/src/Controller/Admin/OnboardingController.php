<?php

namespace App\Controller\Admin;

use App\Entity\BetaSignup;
use App\Entity\User;
use App\Enum\BetaSignupStatus;
use App\Join\InvitationDraft;
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
 * The onboarding desk (IP-157, Phase 5): beta signups from /join, worked by hand.
 *
 * `#[AdminRoute]` on a plain controller for the reason QuestBuilderController gives. Every
 * write is a POST with a session CSRF token and redirects back to the same filter, so the
 * browser's back button never re-posts. There is no mail transport by the researcher's call
 * (proposal Q4): an invitation downloads as .eml. "Mark invited" is a separate status change
 * after the operator sends the message in their mail client.
 */
#[IsGranted('ROLE_SUPERADMIN')]
final class OnboardingController extends AbstractController
{
    private const CSRF = 'onboarding';

    /** The desk's default view: what still needs a decision. */
    private const DEFAULT_STATUSES = [BetaSignupStatus::NEW, BetaSignupStatus::INVITED];

    public function __construct(
        private readonly BetaSignupRepository $signups,
        private readonly EntityManagerInterface $em,
        private readonly InvitationDraft $drafts,
        private readonly string $apiBaseUrl,
    ) {
    }

    #[AdminRoute(path: '/people/onboarding', name: 'people_onboarding')]
    public function index(Request $request): Response
    {
        $statuses = $this->statusFilter($request);
        $rows = [];
        foreach ($this->signups->findByStatuses($statuses) as $signup) {
            $rows[] = ['signup' => $signup];
        }

        return $this->render('admin/onboarding.html.twig', [
            'counts' => $this->signups->countByStatus(),
            'rows' => $rows,
            'statuses' => array_map(static fn (BetaSignupStatus $s) => $s->value, $statuses),
            'all_statuses' => array_map(static fn (BetaSignupStatus $s) => $s->value, BetaSignupStatus::cases()),
            'filter' => self::filterParam($statuses),
        ]);
    }

    #[AdminRoute(path: '/people/onboarding/{id}/invitation.eml', name: 'people_onboarding_draft', options: ['methods' => ['GET']])]
    public function draft(string $id): Response
    {
        $signup = Uuid::isValid($id) ? $this->signups->find(Uuid::fromString($id)) : null;
        if (!$signup instanceof BetaSignup) {
            throw $this->createNotFoundException('No beta signup with that id.');
        }
        $sender = $this->getUser();
        if (!$sender instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!in_array($signup->getStatus(), [BetaSignupStatus::NEW, BetaSignupStatus::INVITED], true)) {
            throw $this->createNotFoundException('This signup is no longer awaiting an invitation.');
        }
        $message = $this->drafts->create($signup, $sender, rtrim($this->apiBaseUrl, '/') . $this->generateUrl('privacy_policy'));

        return new Response($message->toString(), Response::HTTP_OK, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => sprintf('attachment; filename="monadcount-invitation-%s.eml"', $signup->getId()),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The current filter as text/csv: what a spreadsheet or a mail merge needs and nothing more. */
    #[AdminRoute(path: '/people/onboarding/export.csv', name: 'people_onboarding_export')]
    public function export(Request $request): Response
    {
        $statuses = $this->statusFilter($request);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream.');
        }
        fputcsv($handle, ['email', 'name', 'platform', 'availability', 'updates_opt_in', 'status', 'created_at', 'invited_at'], escape: '\\');
        foreach ($this->signups->findByStatuses($statuses, 10_000) as $s) {
            fputcsv($handle, [
                $s->getEmail(),
                $s->getName() ?? '',
                $s->getPlatform()->value,
                $s->getAvailability()->value,
                $s->isUpdatesOptIn() ? '1' : '0',
                $s->getStatus()->value,
                $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
                $s->getInvitedAt()?->format(\DateTimeInterface::ATOM) ?? '',
            ], escape: '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $name = sprintf('beta-signups-%s-%s.csv', self::filterParam($statuses) ?: 'all', (new \DateTimeImmutable())->format('Ymd'));

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', str_replace(',', '+', $name)),
        ]);
    }

    #[AdminRoute(path: '/people/onboarding/{id}/invite', name: 'people_onboarding_invite', options: ['methods' => ['POST']])]
    public function invite(string $id, Request $request): RedirectResponse
    {
        $signup = $this->guarded($id, $request);
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $this->transition($signup, static fn (BetaSignup $s) => $s->markInvited($user), sprintf('%s marked invited.', $signup->getEmail()));

        return $this->back($request);
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
            throw $this->createAccessDeniedException('Stale form: reload the desk and try again.');
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

    private function back(Request $request): RedirectResponse
    {
        $filter = $request->request->getString('filter');

        return $this->redirectToRoute('admin_people_onboarding', $filter === '' ? [] : ['status' => $filter]);
    }

    /**
     * `?status=new,invited` (the default), `?status=all`, any comma list of statuses, or the
     * checkbox form's `?s[]=new&s[]=invited`. Unknown names are dropped; a list that ends up
     * empty means the default, not everything.
     *
     * @return list<BetaSignupStatus>
     */
    private function statusFilter(Request $request): array
    {
        $boxes = array_filter($request->query->all('s'), 'is_string');
        $raw = $boxes !== [] ? implode(',', $boxes) : trim($request->query->getString('status'));
        if ($raw === '') {
            return self::DEFAULT_STATUSES;
        }
        if ($raw === 'all') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $name) {
            $case = BetaSignupStatus::tryFrom(trim($name));
            if ($case !== null) {
                $out[$case->value] = $case;
            }
        }

        return $out === [] ? self::DEFAULT_STATUSES : array_values($out);
    }

    /** @param list<BetaSignupStatus> $statuses */
    private static function filterParam(array $statuses): string
    {
        return $statuses === [] ? 'all' : implode(',', array_map(static fn (BetaSignupStatus $s) => $s->value, $statuses));
    }

}
