<?php

namespace App\Controller\Admin;

use App\Entity\QuestEnrollment;
use App\Repository\LabSessionRepository;
use App\Repository\QuestEnrollmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Quarantine and release for runs (Version20261023120000), from the two reading pages.
 *
 * Replaces the enrollment Delete button as the way to take a run out of the study: a delete
 * cascaded into step completions and left the recording, its scans and its S3 artefacts behind
 * unmarked. A quarantine changes nothing that was recorded. Every write is a POST with a session
 * CSRF token and redirects back to the page it came from, the OnboardingController pattern.
 */
#[IsGranted('ROLE_SUPERADMIN')]
final class RunQuarantineController extends AbstractController
{
    public const CSRF = 'run-quarantine';

    public function __construct(
        private readonly QuestEnrollmentRepository $enrollments,
        private readonly LabSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AdminRoute(path: '/runs/enrollments/{id}/quarantine', name: 'enrollment_quarantine', options: ['methods' => ['POST']])]
    public function quarantineEnrollment(string $id, Request $request): RedirectResponse
    {
        $enrollment = $this->guardedEnrollment($id, $request);
        $reason = $request->request->getString('reason');
        $this->apply(fn () => $enrollment->quarantine($reason), 'Run quarantined. Nothing it recorded was changed.');

        return $this->redirectToRoute('admin_enrollment', ['id' => $id]);
    }

    #[AdminRoute(path: '/runs/enrollments/{id}/release', name: 'enrollment_release', options: ['methods' => ['POST']])]
    public function releaseEnrollment(string $id, Request $request): RedirectResponse
    {
        $enrollment = $this->guardedEnrollment($id, $request);
        $this->apply(fn () => $enrollment->release(), 'Run released back into the study.');

        return $this->redirectToRoute('admin_enrollment', ['id' => $id]);
    }

    #[AdminRoute(path: '/runs/sessions/{id}/quarantine', name: 'recording_session_quarantine', options: ['methods' => ['POST']])]
    public function quarantineSession(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request);
        $reason = $request->request->getString('reason');
        $this->apply(fn () => $this->sessions->quarantine($id, $reason), 'Recording quarantined. Nothing it recorded was changed.', flush: false);

        return $this->redirectToRoute('admin_recording_session', ['id' => $id]);
    }

    #[AdminRoute(path: '/runs/sessions/{id}/release', name: 'recording_session_release', options: ['methods' => ['POST']])]
    public function releaseSession(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request);
        $this->apply(fn () => $this->sessions->release($id), 'Recording released back into the study.', flush: false);

        return $this->redirectToRoute('admin_recording_session', ['id' => $id]);
    }

    private function guardedEnrollment(string $id, Request $request): QuestEnrollment
    {
        $this->guardCsrf($request);
        $enrollment = Uuid::isValid($id) ? $this->enrollments->find(Uuid::fromString($id)) : null;
        if (!$enrollment instanceof QuestEnrollment) {
            throw new NotFoundHttpException('No enrollment with that id.');
        }

        return $enrollment;
    }

    private function guardCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Stale form: reload the page and try again.');
        }
    }

    /**
     * Run the change; a refusal (blank reason, already quarantined, not quarantined) becomes a
     * warning and changes nothing.
     */
    private function apply(callable $change, string $done, bool $flush = true): void
    {
        try {
            $change();
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());

            return;
        }
        if ($flush) {
            $this->em->flush();
        }
        $this->addFlash('success', $done);
    }
}
