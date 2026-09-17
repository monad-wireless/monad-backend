<?php

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Entity\GroundTruthConflict;
use App\Entity\GroundTruthScan;
use App\Entity\Handset;
use App\Entity\LabSession;
use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use App\Entity\User;
use App\Fleet\FleetMetricsReader;
use App\Quest\ArmingMatrixBuilder;
use App\Quest\RealisedRoute;
use App\Repository\BetaSignupRepository;
use App\Repository\GroundTruthConflictRepository;
use App\Repository\HandsetRepository;
use App\Repository\LabSessionRepository;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\QuestEnrollmentRepository;
use App\Repository\QuestRepository;
use App\Service\GroundTruthService;
use App\Service\LabConfigService;
use App\Service\MarkerService;
use App\Service\S3Service;
use App\Service\WalkFigureUrlSigner;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The management interface's front door, and every page that is a READING rather than a form.
 *
 * Ordered by what an operator needs while people are still in the room: the runs (what was
 * recorded, by whom, on what) come first, then the lab views that can still change what happens
 * tonight, then people, then content — the largest surface and the least urgent.
 *
 * The custom pages live on this controller rather than one of their own so they inherit the
 * EasyAdmin context: sidebar, user menu and layout come from the same place the CRUD pages get
 * them, instead of a second half-styled shell.
 *
 * NOTHING HERE WRITES. Every page reads Postgres, and two read the fleet's metrics store and the
 * object store through services that already report unreachability as a fact rather than as zeros.
 * The figures on the session and enrollment pages are `<img>` tags pointing at monad-knowledge web
 * through signed URLs (IP-149 Part D); the browser fetches them, this container never does.
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_SUPERADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GroundTruthService $groundTruth,
        private readonly LabConfigService $labConfig,
        private readonly LabSessionRepository $sessions,
        private readonly QuestEnrollmentRepository $enrollments,
        private readonly HandsetRepository $handsets,
        private readonly QuestRepository $quests,
        private readonly FleetMetricsReader $fleet,
        private readonly ArmingMatrixBuilder $matrix,
        private readonly WalkFigureUrlSigner $figures,
        private readonly S3Service $s3,
        private readonly MarkerService $markers,
        private readonly BetaSignupRepository $signups,
        private readonly GroundTruthConflictRepository $conflicts,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly string $adminTimezone,
    ) {
    }

    // ── Overview ─────────────────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $now = new \DateTimeImmutable();
        $snapshot = $this->fleet->snapshot();

        // Live quests first, because "what can someone in the building start right now" is the
        // question the page opens with. `true` includes the operator audience: an operator
        // reading this page is exactly who those are for.
        $live = $this->quests->findCurrentlyAvailable(true);
        $midnight = $now->setTime(0, 0);

        return $this->render('admin/dashboard.html.twig', [
            'attention' => $this->attention($now, $snapshot),
            'live_quests' => $live,
            'quest_runs' => $this->enrollments->countsForQuests($live, $midnight),
            'recent_enrollments' => $this->enrollments->findRecent(10),
            'week' => $this->enrollments->activitySince($now->modify('-7 days'))
                + $this->sessions->activitySince($now->modify('-7 days')),
            'fleet' => $snapshot,
            'counts' => $this->counts(),
            'runs_url' => $this->generateUrl('admin_runs'),
        ]);
    }

    /**
     * WHAT NEEDS A HUMAN, computed here rather than drawn as seven tiles.
     *
     * The contract, and the reason the page is worth opening: an item with a count of zero is
     * ABSENT. A wall of green zeros trains a reader to skim, and the one row that is not zero
     * then reads the same as the six that are. When every count is zero the list prints one
     * sentence instead, which is a statement and not an empty box.
     *
     * Each item names the page that fixes it. Nothing here writes, and nothing here is derived
     * from another item: the fleet row comes from the metrics store, the rest from Postgres,
     * and an unreachable metrics store contributes NO row rather than a row of zeros — "not
     * reporting" and "could not ask" are two facts and only one of them is actionable here.
     *
     * @param array<string, mixed> $fleet the FleetMetricsReader snapshot
     * @return list<array{state: string, count: int|null, text: string, href: string}>
     */
    private function attention(\DateTimeImmutable $now, array $fleet): array
    {
        $items = [];
        $add = static function (array &$items, int $count, string $state, string $text, string $href): void {
            if ($count > 0) {
                $items[] = ['state' => $state, 'count' => $count, 'text' => $text, 'href' => $href];
            }
        };

        // Values a live quest names that fold to no mirrored card or node: a participant would
        // scan a code nothing recognises.
        $add(
            $items,
            count($this->markers->driftReport()['mismatch']),
            'bad',
            'marker values a live quest names that the placement mirror does not have',
            $this->generateUrl('admin_lab_placements'),
        );

        $signups = $this->signups->countByStatus();
        $add(
            $items,
            (int) ($signups['new'] ?? 0),
            'warn',
            'signups waiting on an invitation',
            $this->generateUrl('admin_people_onboarding'),
        );

        // Streams landed, metadata.json never did: an upload that stopped half way.
        $add(
            $items,
            $this->sessions->activitySince(null)['incomplete'],
            'warn',
            'uploaded sessions with no sidecar',
            $this->generateUrl('admin_runs'),
        );

        $add(
            $items,
            $this->conflicts->countSince($now->modify('-7 days')),
            'bad',
            'ground-truth conflicts (E3) in the last seven days',
            $this->generateUrl('admin_runs'),
        );

        $add(
            $items,
            count($this->quests->findClosingBetween($now, $now->modify('+7 days'))),
            'warn',
            'quests whose window closes within seven days',
            $this->generateUrl('admin_lab_quests'),
        );

        // Only when the store answered. `reachable: false` is its own sentence on the Lab page.
        if (($fleet['reachable'] ?? false) === true) {
            $expected = (int) round((float) ($fleet['scalars']['nodes_expected'] ?? 0));
            $reporting = (int) round((float) ($fleet['scalars']['nodes_reporting'] ?? 0));
            $add($items, max(0, $expected - $reporting), 'bad', 'fleet nodes not reporting', $this->generateUrl('admin_lab'));
        }

        $add(
            $items,
            $this->deliveries->countFailed(),
            'bad',
            'notification pushes the worker could not deliver',
            $this->generateUrl('admin_people_notifications'),
        );

        return $items;
    }

    // ── Runs ─────────────────────────────────────────────────────────────────────────────────

    /**
     * One recording session: the sidecar block by block, the artefacts with download links, the
     * reduction numbers and the figures from monad-knowledge web, and the enrollment behind it.
     */
    #[AdminRoute(path: '/runs/sessions/{id}', name: 'recording_session')]
    public function recordingSession(string $id): Response
    {
        $session = $this->sessions->find($id);
        if (!$session instanceof LabSession) {
            throw new NotFoundHttpException('No recording session with that id.');
        }

        // Presigned links are best-effort: an unreachable object store must not take the page
        // down, because everything else on it comes from Postgres.
        $downloads = [];
        foreach (array_keys($session->getArtefacts()) as $filename) {
            try {
                $downloads[$filename] = $this->s3->presignedSessionGetUrl($session->getParticipantId(), $session->getId(), $filename);
            } catch (\Throwable) {
                $downloads[$filename] = null;
            }
        }

        // Ground truth that names this recording: scans carry `recording_session_id`.
        $scans = $this->entityManager->getRepository(GroundTruthScan::class)
            ->findBy(['recordingSessionId' => $session->getId()], ['receivedAt' => 'ASC'], 200);

        return $this->render('admin/recording_session.html.twig', [
            'session' => $session,
            'downloads' => $downloads,
            'sidecar_blocks' => $this->sidecarBlocks($session->getSidecar()),
            'figures' => $this->figuresFor($session),
            'scans' => $scans,
        ]);
    }

    /**
     * One run, read end to end: who, on what phone, at which node, step by step, and what it
     * uploaded.
     */
    #[AdminRoute(path: '/runs/enrollments/{id}', name: 'enrollment')]
    public function enrollment(string $id): Response
    {
        $enrollment = Uuid::isValid($id) ? $this->enrollments->find(Uuid::fromString($id)) : null;
        if (!$enrollment instanceof QuestEnrollment) {
            throw new NotFoundHttpException('No enrollment with that id.');
        }

        // The steps THIS walker was asked for, in the order they were served (IP-145), each
        // paired with what the phone reported for it. A completion with no step row (a step
        // deleted since) is kept at the end rather than dropped.
        $quest = $enrollment->getQuest();
        $ordered = $quest !== null
            ? RealisedRoute::apply($quest->getSteps()->toArray(), $enrollment->getRealisedSteps())
            : [];
        $byStep = [];
        foreach ($enrollment->getStepCompletions() as $completion) {
            $byStep[(string) $completion->getStep()?->getId()] = $completion;
        }
        $timeline = [];
        foreach ($ordered as $index => $step) {
            $completion = $byStep[(string) $step->getId()] ?? null;
            unset($byStep[(string) $step->getId()]);
            $timeline[] = ['index' => $index + 1, 'step' => $step, 'completion' => $completion];
        }
        foreach ($byStep as $completion) {
            $timeline[] = ['index' => null, 'step' => $completion->getStep(), 'completion' => $completion];
        }

        $sessions = $this->sessions->findForEnrollment($enrollment);
        $sessionIds = array_map(static fn (LabSession $s) => $s->getId(), $sessions);
        $scans = $sessionIds === [] ? [] : $this->entityManager->createQuery(
            'SELECT s FROM App\Entity\GroundTruthScan s WHERE s.recordingSessionId IN (:ids) ORDER BY s.receivedAt ASC'
        )->setParameter('ids', $sessionIds)->setMaxResults(200)->getResult();

        $figures = [];
        foreach ($sessions as $session) {
            $figures[$session->getId()] = $this->figuresFor($session);
        }

        return $this->render('admin/enrollment.html.twig', [
            'enrollment' => $enrollment,
            'timeline' => $timeline,
            'sessions' => $sessions,
            'figures' => $figures,
            'scans' => $scans,
            'snapshot_blocks' => $this->descriptorBlocks($enrollment->getHandsetSnapshot()),
        ]);
    }

    /** One installation: the latest descriptor as tables, and every run and session on it. */
    #[AdminRoute(path: '/runs/handsets/{id}', name: 'handset')]
    public function handset(string $id): Response
    {
        $handset = Uuid::isValid($id) ? $this->handsets->find(Uuid::fromString($id)) : null;
        if (!$handset instanceof Handset) {
            throw new NotFoundHttpException('No handset with that id.');
        }

        return $this->render('admin/handset.html.twig', [
            'handset' => $handset,
            'blocks' => $this->descriptorBlocks($handset->getLastDescriptor()),
            'enrollments' => $this->enrollments->findRecent(200, null, $handset),
            'sessions' => $this->sessions->findBy(['handset' => $handset], ['firstArtefactAt' => 'DESC'], 200),
            'by_machine' => $this->handsets->countByMachine(),
        ]);
    }

    /**
     * One participant: runs, sessions, the phones they used, and what their walking produced.
     *
     * `{id}` is constrained to a UUID because /admin/people/onboarding and
     * /admin/people/notifications (IP-157) sit under the same prefix and this route is
     * registered first; without the requirement "onboarding" matched here as an id.
     */
    #[AdminRoute(path: '/people/{id}', name: 'participant', options: ['requirements' => ['id' => '[0-9a-fA-F-]{36}']])]
    public function participant(string $id): Response
    {
        $user = Uuid::isValid($id) ? $this->entityManager->getRepository(User::class)->find(Uuid::fromString($id)) : null;
        if (!$user instanceof User) {
            throw new NotFoundHttpException('No user with that id.');
        }

        $enrollments = $this->enrollments->findRecent(200, $user);
        $handsets = [];
        foreach ($enrollments as $enrollment) {
            $h = $enrollment->getHandset();
            if ($h !== null) {
                $handsets[$h->getId()->toRfc4122()] = $h;
            }
        }

        return $this->render('admin/participant.html.twig', [
            'participant' => $user,
            'stats' => $this->enrollments->statsForUser($user),
            'enrollments' => $enrollments,
            'sessions' => $this->sessions->findForUser($user, 200),
            'handsets' => array_values($handsets),
        ]);
    }

    // ── Lab operations ───────────────────────────────────────────────────────────────────────

    /** Quests × nodes, unfiltered: armed and blocked as the two independent facts they are. */
    #[AdminRoute(path: '/lab/arming', name: 'lab_arming')]
    public function labArming(): Response
    {
        return $this->render('admin/arming_matrix.html.twig', ['matrix' => $this->matrix->build()]);
    }

    /** The fleet's vital signs from the closed allow-list. Unreachable is a sentence, never zeros. */
    #[AdminRoute(path: '/lab/fleet', name: 'lab_fleet')]
    public function labFleet(): Response
    {
        return $this->render('admin/fleet_vitals.html.twig', ['fleet' => $this->fleet->snapshot()]);
    }

    /**
     * Every ground-truth session that has produced scans.
     *
     * There is no entity behind these — a session is whatever `lab_session_id` the phones were told
     * to stamp, and it comes into existence when the first scan arrives — so the list is a GROUP BY
     * over the scans. A session nobody scanned into produced no ground truth and is correctly absent.
     * NOT the recording sessions (`LabSession`), which are one phone's uploaded artefacts.
     */
    #[AdminRoute(path: '/lab/sessions', name: 'lab_sessions')]
    public function labSessions(): Response
    {
        return $this->render('admin/lab_sessions.html.twig', [
            'sessions' => $this->recentLabSessions(200),
        ]);
    }

    /**
     * One ground-truth session's live tally, through the same GroundTruthService the phones and
     * `/api/lab/ground-truth/{id}` use — an operator comparing the console on the wall with a handset
     * in the room must not be able to see two different numbers.
     */
    #[AdminRoute(path: '/lab/sessions/{labSessionId}', name: 'lab_session')]
    public function labSession(string $labSessionId): Response
    {
        $recordingIds = array_values(array_unique(array_filter($this->entityManager->createQuery(
            'SELECT DISTINCT s.recordingSessionId FROM App\Entity\GroundTruthScan s WHERE s.labSessionId = :id'
        )->setParameter('id', $labSessionId)->getSingleColumnResult())));

        return $this->render('admin/lab_session.html.twig', [
            'lab_session_id' => $labSessionId,
            'aggregate' => $this->groundTruth->aggregate($labSessionId),
            'conflicts' => $this->entityManager->getRepository(GroundTruthConflict::class)
                ->findBy(['labSessionId' => $labSessionId], ['observedAt' => 'DESC'], 50),
            'recent_scans' => $this->entityManager->getRepository(GroundTruthScan::class)
                ->findBy(['labSessionId' => $labSessionId], ['receivedAt' => 'DESC'], 50),
            'recording_sessions' => $recordingIds === [] ? [] : $this->sessions->findBy(['id' => $recordingIds]),
        ]);
    }

    /**
     * The lab bundle exactly as /api/lab/config serves it. Read-only: Ansible renders it from
     * inventory and bind-mounts it read-only, so a form here would write a file the next run
     * silently reverts.
     */
    #[AdminRoute(path: '/lab/bundle', name: 'lab_bundle')]
    public function labBundle(): Response
    {
        $bundle = $this->labConfig->bundle();
        $collectorHost = (string) ($bundle['collector']['host'] ?? '');
        // `beacons` is an object — {uuid, majors, zones} — and it is the zones that become
        // CoreLocation regions. Counting the object's own keys would have reported 3 forever.
        $beaconCount = is_array($bundle['beacons']['zones'] ?? null) ? count($bundle['beacons']['zones']) : 0;

        return $this->render('admin/lab_bundle.html.twig', [
            'bundle' => $bundle,
            'json' => json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'warnings' => $this->bundleWarnings($bundle, $collectorHost, $beaconCount),
        ]);
    }

    // ── Quest analytics ──────────────────────────────────────────────────────────────────────

    /** Every quest with its funnel, linking to the per-quest page. */
    #[AdminRoute(path: '/quests/analytics', name: 'quests_analytics')]
    public function questsAnalytics(): Response
    {
        $rows = [];
        foreach ($this->quests->findBy([], ['availableFrom' => 'DESC']) as $quest) {
            if ($quest instanceof Quest) {
                $rows[] = ['quest' => $quest, 'analytics' => $this->enrollments->analyticsForQuest($quest)];
            }
        }

        return $this->render('admin/quests_analytics.html.twig', ['rows' => $rows]);
    }

    /** One quest: funnel, duration distribution, skip reasons, the steps that fail, completions per node. */
    #[AdminRoute(path: '/quests/{id}/analytics', name: 'quest_analytics')]
    public function questAnalytics(string $id): Response
    {
        $quest = Uuid::isValid($id) ? $this->quests->find(Uuid::fromString($id)) : null;
        if (!$quest instanceof Quest) {
            throw new NotFoundHttpException('No quest with that id.');
        }
        $analytics = $this->enrollments->analyticsForQuest($quest);

        return $this->render('admin/quest_analytics.html.twig', [
            'quest' => $quest,
            'analytics' => $analytics,
            'histogram' => self::histogram($analytics['durations']),
        ]);
    }

    // ── EasyAdmin configuration ──────────────────────────────────────────────────────────────

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('MonadCount')
            ->setFaviconPath('/favicon.svg')
            ->renderContentMaximized();
    }

    /**
     * What every CRUD page inherits, so no list can disagree with a reading page (IP-157 Phase 8).
     *
     * THE CLOCK. `yyyy-MM-dd HH:mm:ss` in MONAD_ADMIN_TIMEZONE, the same zone and the same
     * 24-hour shape the `clock` Twig filter prints. Before this, EasyAdmin formatted dates
     * through the ICU default — "Sep 9, 2026, 12:04:31 PM" — beside "2026-09-09 14:04:31 CEST"
     * two panels away, on the same instant. The zone was the worse half: the CRUD columns were
     * the UTC host's and nothing said so.
     *
     * THE `⋯` MENU IS GONE. `showEntityActionsInlined()` puts the actions in the row as text;
     * the dropdown hid one click behind another and was the tour's first complaint.
     *
     * Fifty rows, because these lists are read by scrolling and not by paging.
     */
    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDateTimeFormat('yyyy-MM-dd HH:mm:ss')
            ->setTimezone($this->adminTimezone)
            ->setPaginatorPageSize(50)
            ->showEntityActionsInlined();
    }

    public function configureAssets(): Assets
    {
        // admin.css carries the site tokens and is loaded after the theme so every rule wins by
        // order. The four per-lane pairs (IP-157) are empty in Phase 1 and are owned by the lane
        // named in each file's header; registering them here means a lane adds rules, not wiring.
        return Assets::new()
            ->addCssFile('admin.css')
            ->addJsFile('admin-ui.js')
            ->addCssFile('admin-quests.css')
            ->addCssFile('admin-placements.css')
            ->addCssFile('admin-notifications.css')
            ->addCssFile('admin-onboarding.css')
            ->addJsFile('admin-quests.js')
            ->addJsFile('admin-placements.js')
            ->addJsFile('admin-notifications.js')
            ->addJsFile('admin-onboarding.js');
    }

    /**
     * SEVEN ENTRIES, BY JOB, NO SECTIONS (IP-157, revision 2026-09-17).
     *
     * Twenty-two entries in four sections was the tour's second complaint, and the reason was
     * not the count: fifteen of them were a table of one entity each, which is a description of
     * the schema rather than of anything an operator does. These seven are the jobs — see who
     * needs attention (Today), onboard and manage people (Participants), author and run scripts
     * (Quests), read what was recorded (Runs), tell participants something (Notifications),
     * check the instrument (Lab), and the two facts about the interface itself (Settings).
     *
     * Nothing was deleted. Every page that left this list is still routable and is linked from
     * the entry that owns it: the six lab pages from Lab, the seven run registers from Runs,
     * the user CRUD from Settings and from Participants, the onboarding desk from Participants.
     * A route that only a menu entry reached would have become unreachable; none did.
     *
     * API docs and Sign out are in the user menu, where an account's own actions belong.
     */
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Today');
        yield MenuItem::linkToRoute('Participants', '', 'admin_people');
        yield MenuItem::linkToRoute('Quests', '', 'admin_lab_quests');
        yield MenuItem::linkToRoute('Runs', '', 'admin_runs');
        yield MenuItem::linkToRoute('Notifications', '', 'admin_people_notifications');
        yield MenuItem::linkToRoute('Lab', '', 'admin_lab');
        yield MenuItem::linkToRoute('Settings', '', 'admin_settings');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * The figure URLs for one session, or the reason there are none.
     *
     * Three outcomes, each a different sentence on the page: the service is not configured; the
     * session has no `pose.tsv` and therefore no trajectory to draw; or a list of signed URLs.
     * `site` is added only when the lab bundle names a floor — never guessed from `site`.
     *
     * @return array{urls: array<string, string>, info_url: string|null, reason: string|null, floor: string|null}
     */
    private function figuresFor(LabSession $session): array
    {
        if (!$this->figures->isConfigured()) {
            return ['urls' => [], 'info_url' => null, 'reason' => $this->figures->unconfiguredReason(), 'floor' => null];
        }
        if (!$session->hasArtefact('pose.tsv')) {
            return ['urls' => [], 'info_url' => null, 'reason' => 'This session recorded no trajectory: no pose.tsv among its artefacts.', 'floor' => null];
        }

        $floor = (string) ($this->labConfig->bundle()['floor'] ?? '');
        $floor = $floor === '' ? null : $floor;
        $participant = $session->getParticipantId();
        $id = $session->getId();

        $urls = [];
        foreach (['overview', 'trajectory', 'quality', 'speed'] as $view) {
            $url = $this->figures->figureUrl($participant, $id, $view);
            if ($url !== null) {
                $urls[$view] = $url;
            }
        }
        if ($floor !== null && $session->hasArtefact('mesh.ply')) {
            $url = $this->figures->figureUrl($participant, $id, 'site', $floor);
            if ($url !== null) {
                $urls['site'] = $url;
            }
        }

        return ['urls' => $urls, 'info_url' => $this->figures->infoUrl($participant, $id), 'reason' => null, 'floor' => $floor];
    }

    /**
     * The sidecar as an ordered list of named blocks, each a flat map for a two-column table.
     * Nested values inside a block are pretty-printed JSON. The order is the order an operator
     * reads a session in; blocks the sidecar does not have are absent, not empty.
     *
     * @param array<string, mixed>|null $sidecar
     * @return list<array{name: string, rows: array<string, string>}>
     */
    private function sidecarBlocks(?array $sidecar): array
    {
        if ($sidecar === null) {
            return [];
        }
        $order = ['identity', 'environment', 'radio', 'summary', 'lifecycle', 'clock', 'health'];
        $blocks = [];
        foreach ($order as $name) {
            if (is_array($sidecar[$name] ?? null)) {
                $block = $sidecar[$name];
                // The whole descriptor sits inside `environment`; it gets its own tables below.
                if ($name === 'environment') {
                    unset($block['handset']);
                }
                $blocks[] = ['name' => $name, 'rows' => self::flatten($block)];
            }
        }
        foreach ($sidecar as $name => $value) {
            if (!in_array($name, $order, true) && is_array($value)) {
                $blocks[] = ['name' => (string) $name, 'rows' => self::flatten($value)];
            }
        }
        if (is_array($sidecar['environment']['handset'] ?? null)) {
            foreach ($this->descriptorBlocks($sidecar['environment']['handset']) as $block) {
                $blocks[] = ['name' => 'handset · ' . $block['name'], 'rows' => $block['rows']];
            }
        }

        return $blocks;
    }

    /**
     * A handset descriptor as tables: the flat facts, then `capabilities`, `sensors`, `radio`, `state`.
     *
     * @param array<string, mixed>|null $descriptor
     * @return list<array{name: string, rows: array<string, string>}>
     */
    private function descriptorBlocks(?array $descriptor): array
    {
        if ($descriptor === null) {
            return [];
        }
        $flat = array_filter($descriptor, static fn ($v) => !is_array($v));
        $blocks = [['name' => 'facts', 'rows' => self::flatten($flat)]];

        if (is_array($descriptor['capabilities'] ?? null)) {
            $tokens = array_values(array_filter($descriptor['capabilities'], 'is_string'));
            sort($tokens);
            $blocks[] = ['name' => 'capabilities', 'rows' => $tokens === [] ? ['(none)' => ''] : array_fill_keys($tokens, 'claimed')];
        }
        if (is_array($descriptor['sensors'] ?? null)) {
            $rows = [];
            foreach ($descriptor['sensors'] as $i => $sensor) {
                if (!is_array($sensor)) {
                    continue;
                }
                $key = (string) ($sensor['kind'] ?? $sensor['name'] ?? $sensor['type'] ?? "sensor $i");
                $rest = array_diff_key($sensor, ['kind' => 1, 'name' => 1]);
                $rows[$key] = self::scalarOrJson($rest === [] ? ($sensor['available'] ?? '') : $rest);
            }
            $blocks[] = ['name' => 'sensors', 'rows' => $rows === [] ? ['(none reported)' => ''] : $rows];
        }
        foreach (['radio', 'state'] as $name) {
            if (is_array($descriptor[$name] ?? null)) {
                $rows = self::flatten($descriptor[$name]);
                $blocks[] = ['name' => $name, 'rows' => $rows === [] ? ['(platform publishes nothing)' => ''] : $rows];
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, string>
     */
    private static function flatten(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[(string) $key] = self::scalarOrJson($value);
        }

        return $out;
    }

    private static function scalarOrJson(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Twelve equal-width bins over the duration range, for the inline SVG bars.
     *
     * @param list<int> $seconds sorted ascending
     * @return list<array{label: string, value: int}>
     */
    private static function histogram(array $seconds, int $bins = 12): array
    {
        if ($seconds === []) {
            return [];
        }
        $min = $seconds[0];
        $max = $seconds[count($seconds) - 1];
        if ($max === $min) {
            return [['label' => sprintf('%d s', $min), 'value' => count($seconds)]];
        }
        $width = ($max - $min) / $bins;
        $counts = array_fill(0, $bins, 0);
        foreach ($seconds as $s) {
            $i = min($bins - 1, (int) floor(($s - $min) / $width));
            ++$counts[$i];
        }
        $out = [];
        foreach ($counts as $i => $n) {
            $lo = (int) round($min + $i * $width);
            $out[] = ['label' => $lo >= 60 ? sprintf('%d min', intdiv($lo, 60)) : sprintf('%d s', $lo), 'value' => $n];
        }

        return $out;
    }

    /**
     * The two mistakes config/lab/README.md warns about, checked rather than documented.
     *
     * @param array<string, mixed> $bundle
     * @return list<string>
     */
    private function bundleWarnings(array $bundle, string $collectorHost, int $beaconCount): array
    {
        $warnings = [];

        if ($collectorHost === '') {
            $warnings[] = 'No collector host. Phones have nowhere to send traffic, and /api/lab/config is serving an empty bundle.';
        } elseif (filter_var($collectorHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $warnings[] = sprintf(
                'collector.host is "%s". It must be a literal IPv4 address: the phone pins its socket to an interface, and a name resolved over the wrong one silently sends the stream out of the building.',
                $collectorHost,
            );
        }

        // Zero is the failure this page exists to catch, and it is the one the >20 check below
        // would have sailed past: with no zones there is nothing for CoreLocation to monitor, so
        // the app runs, reports itself healthy, and produces no witness events at all. Same for
        // an empty `majors` — a region is identified by UUID *and* major, so an empty list leaves
        // the UUID unusable.
        if ($beaconCount === 0) {
            $warnings[] = 'No beacon zones declared. The witness channel has nothing to monitor: the app will run and report healthy while producing no zone transitions.';
        } elseif (($bundle['beacons']['majors'] ?? []) === []) {
            $warnings[] = 'Beacon zones are declared but `majors` is empty. A CoreLocation region is identified by UUID and major together, so nothing would be monitored.';
        }

        // iOS monitors at most 20 CoreLocation beacon regions per app, and the excess is not an
        // error — the regions past the limit are simply never delivered.
        if ($beaconCount > 20) {
            $warnings[] = sprintf(
                '%d beacons declared. iOS monitors at most 20 regions and drops the rest silently, so the last %d would never be witnessed.',
                $beaconCount,
                $beaconCount - 20,
            );
        }

        if (($bundle['access_points'] ?? []) === []) {
            $warnings[] = 'No access points declared. Emit sessions will log "no AP commanded" and use whatever network the handset is already on.';
        }

        // IP-149 — the admin's `site` figure registers a walk's LiDAR mesh against this floor
        // bundle. Without it the figure is omitted, which is the right failure; this line is so
        // the omission is not a mystery.
        if ((string) ($bundle['floor'] ?? '') === '') {
            $warnings[] = 'No `floor` declared. The admin omits the registered-trajectory figure (`site`) until the bundle names the committed floor bundle, e.g. fiit-ground-0.';
        }

        return $warnings;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $count = function (string $class): int {
            return (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(e.id)')->from($class, 'e')
                ->getQuery()->getSingleScalarResult();
        };

        return [
            'users' => $count(User::class),
            // Roles live in a json column, and PostgreSQL has no LIKE operator for json — the
            // value has to be cast to text first, which DQL cannot express. Hence native SQL
            // rather than a query builder. A substring match is honest here: the column holds a
            // JSON array of role strings, and this is a headline count, not an authorisation
            // decision (those go through the security layer, which reads the array properly).
            'admins' => (int) $this->entityManager->getConnection()->fetchOne(
                "SELECT COUNT(id) FROM users WHERE roles::text LIKE '%ROLE_SUPERADMIN%'"
            ),
            'quests' => $count(Quest::class),
            'devices' => $count(Device::class),
            'handsets' => $count(Handset::class),
            'scans' => $count(GroundTruthScan::class),
            'conflicts' => $count(GroundTruthConflict::class),
        ];
    }

    /**
     * Ground-truth sessions that have produced scans, newest first (see labSessions()).
     *
     * @return list<array{lab_session_id: string, scans: int, participants: int, last_seen: mixed}>
     */
    private function recentLabSessions(int $limit = 8): array
    {
        return $this->entityManager->createQuery(
            'SELECT s.labSessionId AS lab_session_id,
                    COUNT(s.id) AS scans,
                    COUNT(DISTINCT s.participantToken) AS participants,
                    MAX(s.receivedAt) AS last_seen
             FROM App\Entity\GroundTruthScan s
             GROUP BY s.labSessionId
             ORDER BY last_seen DESC'
        )->setMaxResults($limit)->getArrayResult();
    }
}
