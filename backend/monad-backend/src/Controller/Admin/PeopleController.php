<?php

namespace App\Controller\Admin;

use App\Entity\BetaSignup;
use App\Entity\LabSession;
use App\Entity\QuestEnrollment;
use App\Entity\User;
use App\Enum\BetaSignupStatus;
use App\Enum\QuestEnrollmentStatus;
use App\Enum\UserStatus;
use App\Join\AccountProvisioner;
use App\Join\InvitationDraft;
use App\Repository\BetaSignupRepository;
use App\Repository\LabSessionRepository;
use App\Repository\QuestEnrollmentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * People: ONE list over accounts and beta signups, and one page per person (2026-11-23).
 *
 * It replaces three views that each held part of a person: the old Participants index, the
 * onboarding desk and the Users CRUD. The CRUD stays routable for a raw edit, linked from the
 * person page.
 *
 * The researcher onboards by email, not in a meeting: "Create account" on a signup row makes an
 * ACTIVE account with a generated password and answers with the invitation to send. The
 * plaintext exists only in that response (private, no-store) and in nothing stored.
 *
 * `{id}` routes carry a UUID requirement: /people/onboarding and /people/notifications sit
 * under the same prefix.
 */
#[IsGranted('ROLE_SUPERADMIN')]
final class PeopleController extends AbstractController
{
    public const CSRF = 'people';

    /** Stage => tab label, in tab order. `all` is added by the page. */
    private const STAGES = [
        'awaiting' => 'Needs an account',
        'pending' => 'Waiting for approval',
        'active' => 'Participants',
        'operator' => 'Operators',
        'closed' => 'Closed',
    ];

    private const UUID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly UserRepository $users,
        private readonly BetaSignupRepository $signups,
        private readonly QuestEnrollmentRepository $enrollments,
        private readonly LabSessionRepository $sessions,
        private readonly AccountProvisioner $provisioner,
        private readonly InvitationDraft $drafts,
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGenerator $adminUrls,
        private readonly string $apiBaseUrl,
    ) {
    }

    // ── The list ─────────────────────────────────────────────────────────────────────────────

    #[AdminRoute(path: '/people', name: 'people')]
    public function index(Request $request): Response
    {
        $rows = $this->rows();
        $counts = array_fill_keys(array_keys(self::STAGES), 0);
        foreach ($rows as $row) {
            ++$counts[$row['stage']];
        }
        $stage = $request->query->getString('stage');
        if ($stage !== 'all' && !array_key_exists($stage, self::STAGES)) {
            // Open work first: the signups that need an account when there are any.
            $stage = $counts['awaiting'] > 0 ? 'awaiting' : 'all';
        }
        $shown = $stage === 'all' ? $rows : array_values(array_filter($rows, static fn (array $r) => $r['stage'] === $stage));

        return $this->render('admin/people.html.twig', [
            'rows' => $shown,
            'stage' => $stage,
            'stages' => self::STAGES,
            'counts' => $counts,
            'total' => count($rows),
            'users_url' => $this->adminUrls->setController(UserCrudController::class)->setAction('index')->generateUrl(),
        ]);
    }

    // ── The person ───────────────────────────────────────────────────────────────────────────

    /** One person: access, results, quests, runs with their state controls, activity, phones. */
    #[AdminRoute(path: '/people/{id}', name: 'participant', options: ['requirements' => ['id' => self::UUID]])]
    public function participant(string $id): Response
    {
        $user = $this->user($id);
        $enrollments = $this->enrollments->findRecent(500, $user);
        $sessions = $this->sessions->findForUser($user, 500);
        $signup = $this->signupFor($user);

        $handsets = [];
        foreach ($enrollments as $enrollment) {
            $h = $enrollment->getHandset();
            if ($h !== null) {
                $handsets[$h->getId()->toRfc4122()] = $h;
            }
        }

        return $this->render('admin/participant.html.twig', [
            'participant' => $user,
            'signup' => $signup,
            'stats' => $this->enrollments->statsForUser($user),
            'enrollments' => $enrollments,
            'quests' => self::questSummary($enrollments),
            'activity' => self::activity($user, $signup, $enrollments, $sessions),
            'sessions' => $sessions,
            'handsets' => array_values($handsets),
            'run_statuses' => array_map(static fn (QuestEnrollmentStatus $s) => $s->value, QuestEnrollmentStatus::cases()),
            'access_statuses' => [UserStatus::ACTIVE->value, UserStatus::PENDING->value, UserStatus::INACTIVE->value],
            'edit_url' => $this->adminUrls->setController(UserCrudController::class)->setAction('edit')->setEntityId($user->getId())->generateUrl(),
            'anonymise_url' => $this->adminUrls->setController(UserCrudController::class)->setAction('anonymise')->setEntityId($user->getId())->generateUrl(),
        ]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────────────────────

    /**
     * One click: signup -> ACTIVE account with a generated password, answered with the
     * invitation to send. Not a redirect, because the password must not travel in a URL or
     * outlive this response.
     */
    #[AdminRoute(path: '/people/signups/{id}/account', name: 'people_create_account', options: ['methods' => ['POST'], 'requirements' => ['id' => self::UUID]])]
    public function createAccount(string $id, Request $request): Response
    {
        $this->guardCsrf($request);
        $signup = $this->signups->find(Uuid::fromString($id));
        if (!$signup instanceof BetaSignup) {
            throw new NotFoundHttpException('No beta signup with that id.');
        }
        try {
            $result = $this->provisioner->provisionFromSignup($signup, $this->operator());
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
            $user = $signup->getUser() ?? $this->users->findOneByEmailInsensitive($signup->getEmail());

            return $user instanceof User
                ? $this->redirectToRoute('admin_participant', ['id' => $user->getId()?->toRfc4122()])
                : $this->redirectToRoute('admin_people');
        }

        return $this->credentials($result['user'], $result['password'], $signup, $result['created']
            ? 'Account created and active. The signup is now registered.'
            : 'This address already had an account. It is now active and has a new password.');
    }

    /** A new password and a fresh invitation for an existing account. */
    #[AdminRoute(path: '/people/{id}/password', name: 'people_reset_password', options: ['methods' => ['POST'], 'requirements' => ['id' => self::UUID]])]
    public function resetPassword(string $id, Request $request): Response
    {
        $this->guardCsrf($request);
        $user = $this->user($id);
        if ($user === $this->getUser()) {
            $this->addFlash('danger', 'Reset your own password on the account form, not here.');

            return $this->redirectToRoute('admin_participant', ['id' => $id]);
        }
        try {
            $password = $this->provisioner->resetPassword($user);
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('admin_participant', ['id' => $id]);
        }

        return $this->credentials($user, $password, $this->signupFor($user), 'New password set. The old one no longer works.');
    }

    /** Approve (active), hold (pending) or switch off (inactive) sign-in. */
    #[AdminRoute(path: '/people/{id}/access', name: 'people_access', options: ['methods' => ['POST'], 'requirements' => ['id' => self::UUID]])]
    public function access(string $id, Request $request): RedirectResponse
    {
        $this->guardCsrf($request);
        $user = $this->user($id);
        $to = UserStatus::tryFrom($request->request->getString('status'));
        if (!in_array($to, [UserStatus::ACTIVE, UserStatus::PENDING, UserStatus::INACTIVE], true) || $user->isDeleted()) {
            $this->addFlash('warning', 'That access change is not available for this account.');
        } elseif ($user === $this->getUser()) {
            $this->addFlash('danger', 'You cannot change the access of the account you are signed in as.');
        } elseif ($user->getStatus() === $to) {
            $this->addFlash('warning', sprintf('%s is already %s.', $user->getEmail(), $to->value));
        } else {
            $user->setStatus($to);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s is now %s.', $user->getEmail(), $to->value));
        }

        $back = $request->request->getString('stage');

        return $back !== ''
            ? $this->redirectToRoute('admin_people', ['stage' => $back])
            : $this->redirectToRoute('admin_participant', ['id' => $id]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    private function credentials(User $user, string $password, ?BetaSignup $signup, string $done): Response
    {
        $message = $this->drafts->create($user, $password, $this->operator(), rtrim($this->apiBaseUrl, '/') . $this->generateUrl('privacy_policy'), $signup?->getPlatform());
        $response = $this->render('admin/person_credentials.html.twig', [
            'person' => $user,
            'password' => $password,
            'done' => $done,
            'subject' => $message->getSubject(),
            'body' => $message->getTextBody(),
            'eml' => base64_encode($message->toString()),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Accounts and signups merged by person: a signup joins its account through the link
     * register writes, or else through the same lower(email) comparison. A signup with no
     * account is a row of its own.
     *
     * @return list<array{stage: string, user: User|null, signup: BetaSignup|null, runs: array|null, since: \DateTimeImmutable, last_at: \DateTimeImmutable|null}>
     */
    private function rows(): array
    {
        $signupsByUser = [];
        $signupsByEmail = [];
        $loose = [];
        foreach ($this->signups->findByStatuses([], 10_000) as $s) {
            $loose[(string) $s->getId()] = $s;
            if ($s->getUser() !== null) {
                $signupsByUser[(string) $s->getUser()->getId()] = $s;
            }
            $signupsByEmail[mb_strtolower($s->getEmail())] = $s;
        }
        $summary = $this->enrollments->summaryByUser();

        $rows = [];
        foreach ($this->users->findBy([], ['createdAt' => 'DESC'], 5_000) as $u) {
            $key = (string) $u->getId();
            $s = $signupsByUser[$key] ?? ($u->getEmail() !== null ? ($signupsByEmail[mb_strtolower($u->getEmail())] ?? null) : null);
            if ($s !== null) {
                unset($loose[(string) $s->getId()]);
            }
            $runs = $summary[$key] ?? null;
            $rows[] = [
                'stage' => match (true) {
                    $u->isDeleted() || in_array($u->getStatus(), [UserStatus::INACTIVE, UserStatus::BANNED, UserStatus::DELETED], true) => 'closed',
                    $u->getStatus() === UserStatus::PENDING => 'pending',
                    $u->isSuperAdmin() => 'operator',
                    default => 'active',
                },
                'user' => $u,
                'signup' => $s,
                'runs' => $runs,
                'since' => $u->getCreatedAt() ?? new \DateTimeImmutable(),
                'last_at' => $runs['last_at'] ?? null,
            ];
        }
        foreach ($loose as $s) {
            $rows[] = [
                'stage' => in_array($s->getStatus(), [BetaSignupStatus::NEW, BetaSignupStatus::INVITED], true) ? 'awaiting' : 'closed',
                'user' => null,
                'signup' => $s,
                'runs' => null,
                'since' => $s->getCreatedAt(),
                'last_at' => null,
            ];
        }
        usort($rows, static fn (array $a, array $b) => $b['since'] <=> $a['since']);

        return $rows;
    }

    /**
     * Runs grouped by quest, newest run first.
     *
     * @param list<QuestEnrollment> $enrollments
     * @return list<array{name: string, quest_id: string|null, runs: int, completed: int, open: int, ended: int, points: float, last: \DateTimeImmutable|null}>
     */
    private static function questSummary(array $enrollments): array
    {
        $out = [];
        foreach ($enrollments as $e) {
            $q = $e->getQuest();
            $key = $q?->getId()?->toRfc4122() ?? '-';
            $out[$key] ??= ['name' => $q?->getName() ?? 'Unknown quest', 'quest_id' => $q?->getId()?->toRfc4122(), 'runs' => 0, 'completed' => 0, 'open' => 0, 'ended' => 0, 'points' => 0.0, 'last' => $e->getCreatedAt()];
            ++$out[$key]['runs'];
            match ($e->getStatus()) {
                QuestEnrollmentStatus::COMPLETED => ++$out[$key]['completed'],
                QuestEnrollmentStatus::IN_PROGRESS => ++$out[$key]['open'],
                default => ++$out[$key]['ended'],
            };
            if ($e->getStatus() === QuestEnrollmentStatus::COMPLETED) {
                $out[$key]['points'] += $e->getPointsAwarded() ?? 0.0;
            }
        }

        return array_values($out);
    }

    /**
     * What this database recorded about the person, newest first. Sign-ins are not recorded.
     *
     * @param list<QuestEnrollment> $enrollments
     * @param list<LabSession>      $sessions
     * @return list<array{at: \DateTimeInterface, what: string, detail: string, href: array{route: string, id: string}|null}>
     */
    private static function activity(User $user, ?BetaSignup $signup, array $enrollments, array $sessions): array
    {
        $events = [];
        $add = static function (?\DateTimeInterface $at, string $what, string $detail = '', ?array $href = null) use (&$events): void {
            if ($at !== null) {
                $events[] = ['at' => $at, 'what' => $what, 'detail' => $detail, 'href' => $href];
            }
        };
        if ($signup !== null) {
            $add($signup->getCreatedAt(), 'Signed up', 'beta form, ' . $signup->getPlatform()->value);
            $add($signup->getInvitedAt(), 'Invited', $signup->getInvitedBy()?->getEmail() ?? '');
            $add($signup->getRegisteredAt(), 'Signup linked to the account');
        }
        $add($user->getCreatedAt(), 'Account created');
        foreach ($enrollments as $e) {
            $run = ['route' => 'admin_enrollment', 'id' => (string) $e->getId()?->toRfc4122()];
            $quest = $e->getQuest()?->getName() ?? 'Unknown quest';
            $add($e->getCreatedAt(), 'Started a run', $quest . ($e->getDevice() !== null ? ' at ' . $e->getDevice()->getSlug() : ''), $run);
            $add($e->getCompletionReceivedAt(), 'Completion received', $quest, $run);
            $add($e->getQuarantinedAt(), 'Run quarantined', $quest, $run);
        }
        foreach ($sessions as $s) {
            $add($s->getFirstArtefactAt(), 'Recording uploaded', $s->shortId() . ($s->isComplete() ? '' : ', no sidecar yet'), ['route' => 'admin_recording_session', 'id' => $s->getId()]);
        }
        usort($events, static fn (array $a, array $b) => $b['at'] <=> $a['at']);

        return array_slice($events, 0, 300);
    }

    private function signupFor(User $user): ?BetaSignup
    {
        $linked = $this->signups->findOneBy(['user' => $user]);
        if ($linked instanceof BetaSignup) {
            return $linked;
        }

        return $user->getEmail() !== null ? $this->signups->findByEmail($user->getEmail()) : null;
    }

    private function user(string $id): User
    {
        $user = Uuid::isValid($id) ? $this->users->find(Uuid::fromString($id)) : null;
        if (!$user instanceof User) {
            throw new NotFoundHttpException('No user with that id.');
        }

        return $user;
    }

    private function operator(): User
    {
        $operator = $this->getUser();
        if (!$operator instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $operator;
    }

    private function guardCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Stale form: reload the page and try again.');
        }
    }
}
