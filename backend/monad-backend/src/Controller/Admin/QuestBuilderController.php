<?php

namespace App\Controller\Admin;

use App\Entity\LabPlacement;
use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Entity\User;
use App\Enum\QuestStepType;
use App\Form\QuestHeaderData;
use App\Form\QuestHeaderType;
use App\Quest\QuestPreflight;
use App\Quest\QuestSpecMapper;
use App\Quest\Schema\FieldSpec;
use App\Quest\Schema\StepSchemaRegistry;
use App\Repository\LabPlacementRepository;
use App\Repository\QuestRepository;
use App\Repository\QuestStepCompletionRepository;
use App\Service\LabConfigService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The quest builder (IP-157, Phase 2).
 *
 * `#[AdminRoute]` rather than a plain `#[Route]`: EasyAdmin generates the route under the
 * dashboard (`/admin/lab/quests`, name `admin_lab_quests`) and creates the AdminContext for it,
 * which `@EasyAdmin/page/content.html.twig` needs (`ea()` returns null on a route EasyAdmin did
 * not create, and the layout dereferences it on its first line). The class stays a plain
 * AbstractController so the page has no CRUD semantics.
 *
 * One page edits a quest: a Symfony Form for the header (QuestHeaderType) and a JS island for
 * the steps (public/admin-quests.js) that posts one hidden JSON field. The server never trusts
 * the island: every step goes through QuestSpecMapper::buildSteps(), which is the entity
 * constraint on QuestStep::$config, and violations come back per step index. Preflight
 * (QuestPreflight) is rendered on every GET and POST; it warns and never blocks.
 *
 * The lock rule is `lab_quest_update`'s: a quest with any step completion keeps its step rows
 * (the FK on quest_step_completions.step_id has no ON DELETE clause), so the island is
 * read-only and the page offers Duplicate; header fields stay editable.
 */
#[IsGranted('ROLE_SUPERADMIN')]
class QuestBuilderController extends AbstractController
{
    /** The floor the placement mirror is read for when neither the query nor the bundle names one. */
    public const DEFAULT_FLOOR = 'fiit-ground-0';

    private const CSRF_ID = 'quest_builder';
    private const UUID = ['requirements' => ['id' => '[0-9a-fA-F-]{36}']];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuestRepository $quests,
        private readonly QuestStepCompletionRepository $completions,
        private readonly LabPlacementRepository $placements,
        private readonly LabConfigService $labConfig,
        private readonly StepSchemaRegistry $registry,
        private readonly QuestSpecMapper $mapper,
        private readonly QuestPreflight $preflight,
    ) {
    }

    // ── index ────────────────────────────────────────────────────────────────────────────────

    #[AdminRoute(path: '/lab/quests', name: 'lab_quests')]
    public function index(): Response
    {
        return $this->renderIndex();
    }

    // ── new / edit ───────────────────────────────────────────────────────────────────────────

    #[AdminRoute(path: '/lab/quests/new', name: 'lab_quests_new', options: ['methods' => ['GET', 'POST']])]
    public function new(Request $request): Response
    {
        return $this->form($request, null);
    }

    #[AdminRoute(path: '/lab/quests/{id}/edit', name: 'lab_quests_edit', options: self::UUID + ['methods' => ['GET', 'POST']])]
    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->questOr404($id));
    }

    private function form(Request $request, ?Quest $quest): Response
    {
        $isNew = $quest === null;
        $locked = !$isNew && $this->completions->countForQuest($quest) > 0;
        $storedSteps = $isNew ? [] : $this->stepsOf($quest);

        if ($isNew) {
            $data = new QuestHeaderData();
            $data->availableFrom = new \DateTimeImmutable();
            $data->stepsJson = self::encode([
                ['name' => 'Before you start', 'type' => QuestStepType::START->value, 'config' => []],
                ['name' => 'Run complete', 'type' => QuestStepType::FINISH->value, 'config' => []],
            ]);
        } else {
            $data = QuestHeaderData::fromQuest($quest);
            $data->stepsJson = self::encode($storedSteps);
        }

        $form = $this->createForm(QuestHeaderType::class, $data);
        $form->handleRequest($request);

        $stepViolations = [];
        $steps = $isNew ? self::decodeSteps($data->stepsJson) : $storedSteps;
        $saved = false;

        if ($form->isSubmitted()) {
            if ($locked) {
                // The island is read-only: whatever was posted, the stored steps stand, and
                // the session features on the start step stand with them.
                $fixed = QuestHeaderData::fromQuest($quest);
                $data->featureBroadcast = $fixed->featureBroadcast;
                $data->featureTrack = $fixed->featureTrack;
                $data->featureWitness = $fixed->featureWitness;
                $data->featureIlluminator = $fixed->featureIlluminator;
            } else {
                $steps = self::decodeSteps($data->stepsJson);
                if ($steps === null) {
                    $form->get('stepsJson')->addError(new FormError('The step list is not valid JSON.'));
                    $steps = $storedSteps;
                }
                $steps = self::withFeatures($steps, $data->features());
            }

            $built = $this->mapper->buildSteps($steps);
            $stepViolations = $built['violations'];
            foreach (QuestSpecMapper::routeViolations($data->parsedRoutes(), QuestSpecMapper::normaliseSteps($steps)) as $message) {
                $form->get('routes')->addError(new FormError($message));
            }

            if ($form->isValid() && $stepViolations === []) {
                $quest ??= new Quest();
                if ($isNew) {
                    $quest->setCreatedBy($this->author());
                }
                $spec = self::specFromData($data, $steps);
                $derived = $this->preflight->run($spec, [])['required_capabilities'];
                $data->applyTo($quest, $derived);
                if (!$locked) {
                    $this->replaceSteps($quest, $built['steps'], $isNew);
                }
                $this->entityManager->persist($quest);
                $this->entityManager->flush();
                $saved = true;
            }
        }

        if ($saved) {
            $this->addFlash('success', sprintf('%s "%s".', $isNew ? 'Created' : 'Saved', $quest->getName()));

            return $this->redirectToRoute('admin_lab_quests_edit', ['id' => $quest->getId()?->toRfc4122()]);
        }

        $floor = $this->floor($request);
        $mirror = $this->mirror($floor);
        $spec = self::specFromData($data, $steps);
        $preflight = $this->preflight->run($spec, $mirror, $quest?->getUpdatedAt());

        return $this->render('admin/quests/edit.html.twig', [
            'quest' => $quest,
            'form' => $form,
            'locked' => $locked,
            'completions' => $locked ? $this->completions->countForQuest($quest) : 0,
            'floor' => $floor,
            'floors' => $this->placements->floors(),
            'steps_json' => self::encode($steps),
            'schemas_json' => self::encode($this->schemas()),
            'placements_json' => self::encode(array_map(static fn (array $r): array => [
                'key' => $r['key'],
                'kind' => $r['kind'],
                'room' => $r['room'],
                'x_m' => $r['x_m'],
                'y_m' => $r['y_m'],
                'synced_at' => $r['synced_at']?->format(\DateTimeInterface::ATOM),
            ], $mirror)),
            'violations_json' => self::encode((object) $stepViolations),
            'step_violations' => $stepViolations,
            'preflight' => $preflight['warnings'],
            'derived_capabilities' => $preflight['required_capabilities'],
            'payload_bytes' => strlen((string) json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'csrf' => self::CSRF_ID,
        ]);
    }

    // ── actions ──────────────────────────────────────────────────────────────────────────────

    /** A revision: the quest and its steps copied, the window reset, the name suffixed with the month. */
    #[AdminRoute(path: '/lab/quests/{id}/duplicate', name: 'lab_quests_duplicate', options: self::UUID + ['methods' => ['POST']])]
    public function duplicate(Request $request, string $id): Response
    {
        $this->checkCsrf($request);
        $source = $this->questOr404($id);

        $copy = new Quest();
        $copy->setName($this->revisionName((string) $source->getName()));
        $copy->setDescription((string) $source->getDescription());
        $copy->setAudience($source->getAudience());
        $copy->setAvailableFrom(new \DateTime());
        $copy->setAvailableTo(null);
        $copy->setPoints($source->getPoints());
        $copy->setEstimatedDuration($source->getEstimatedDuration());
        $copy->setRecurrence($source->getRecurrence());
        $copy->setRequiredCapabilities($source->getRequiredCapabilities());
        $copy->setRoutePolicy($source->getRoutePolicy());
        $copy->setFeaturedImage($source->getFeaturedImage());
        $copy->setCreatedBy($this->author());
        foreach ($source->getArmedDevices() as $device) {
            $copy->addArmedDevice($device);
        }
        foreach ($source->getSteps() as $step) {
            $clone = (new QuestStep())
                ->setName((string) $step->getName())
                ->setType($step->getType())
                ->setOrder((int) $step->getOrder())
                ->setConfig($step->getConfig());
            $copy->addStep($clone);
            $this->entityManager->persist($clone);
        }

        $this->entityManager->persist($copy);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Duplicated as "%s". The window starts now and never closes; adjust it before it goes live.', $copy->getName()));

        return $this->redirectToRoute('admin_lab_quests_edit', ['id' => $copy->getId()?->toRfc4122()]);
    }

    /** `lab_quest_update available_to="now"`: stops the offer, keeps every enrolment and completion. */
    #[AdminRoute(path: '/lab/quests/{id}/hide', name: 'lab_quests_hide', options: self::UUID + ['methods' => ['POST']])]
    public function hide(Request $request, string $id): Response
    {
        $this->checkCsrf($request);
        $quest = $this->questOr404($id);
        $quest->setAvailableTo(new \DateTime());
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('"%s" is hidden: available_to is now.', $quest->getName()));

        return $this->redirectToRoute('admin_lab_quests');
    }

    /** The quest in the `lab_quest_write` argument shape, as a download. */
    #[AdminRoute(path: '/lab/quests/{id}/export', name: 'lab_quests_export', options: self::UUID)]
    public function export(string $id): Response
    {
        $quest = $this->questOr404($id);
        $spec = $this->mapper->toSpec($quest);

        $response = new JsonResponse($spec, 200, [], false);
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $quest->getName())) ?? 'quest', '-') ?: 'quest';
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename . '.json',
        ));

        return $response;
    }

    /**
     * Create or replace by name from a `lab_quest_write` payload, pasted or uploaded.
     *
     * The same rules as the MCP tool: every step validated before anything is written, and a
     * quest with completions is refused (the tool learns that from the FK; this asks first).
     */
    #[AdminRoute(path: '/lab/quests/import', name: 'lab_quests_import', options: ['methods' => ['POST']])]
    public function import(Request $request): Response
    {
        $this->checkCsrf($request);

        $raw = (string) $request->request->get('json', '');
        $file = $request->files->get('file');
        if ($file instanceof UploadedFile && $file->isValid()) {
            $raw = (string) file_get_contents($file->getPathname());
        }

        $errors = [];
        $violations = [];
        $spec = json_decode($raw, true);
        if (trim($raw) === '') {
            $errors[] = 'Nothing to import: paste the JSON or choose a file.';
        } elseif (!is_array($spec) || array_is_list($spec)) {
            $errors[] = 'The payload is not a JSON object' . (json_last_error() !== JSON_ERROR_NONE ? ': ' . json_last_error_msg() : '.');
            $spec = [];
        } elseif (!is_array($spec['steps'] ?? null)) {
            $errors[] = 'The payload has no "steps" list.';
        }

        $quest = null;
        $existed = false;
        if ($errors === []) {
            $name = trim((string) ($spec['name'] ?? ''));
            $quest = $name === '' ? null : $this->quests->findOneBy(['name' => $name]);
            $existed = $quest !== null;
            if ($existed && $this->completions->countForQuest($quest) > 0) {
                $errors[] = sprintf(
                    '"%s" has step completions, so its steps cannot be replaced. Import under a new name, or open it and use Duplicate.',
                    $name,
                );
            }
            $quest ??= new Quest();

            $built = $this->mapper->buildSteps($spec['steps']);
            foreach ($built['violations'] as $i => $messages) {
                foreach ($messages as $message) {
                    $violations[] = sprintf('Step %d (%s): %s', $i, (string) ($spec['steps'][$i]['type'] ?? '?'), $message);
                }
            }
            foreach (QuestSpecMapper::routeViolations(
                is_array($spec['route_pool'] ?? null) ? QuestSpecMapper::routesOf(['mode' => 'pool', 'routes' => $spec['route_pool']]) : [],
                QuestSpecMapper::normaliseSteps($spec['steps']),
            ) as $message) {
                $violations[] = $message;
            }
            if ($errors === [] && $violations === []) {
                $errors = $this->mapper->applyHeader($quest, $spec);
            }
        }

        if ($errors !== [] || $violations !== []) {
            return $this->renderIndex([
                'import_errors' => $errors,
                'import_violations' => $violations,
                'import_json' => $raw,
            ]);
        }

        if (!$existed) {
            $quest->setCreatedBy($this->author());
        }
        $derived = $this->preflight->run($spec, [])['required_capabilities'];
        $declared = is_array($spec['required_capabilities'] ?? null) ? array_filter($spec['required_capabilities'], 'is_string') : [];
        $quest->setRequiredCapabilities(array_merge(array_values($declared), $derived));
        $this->replaceSteps($quest, $built['steps'], !$existed);
        $this->entityManager->persist($quest);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('%s "%s" with %d step(s).', $existed ? 'Replaced' : 'Created', $quest->getName(), count($built['steps'])));

        return $this->redirectToRoute('admin_lab_quests_edit', ['id' => $quest->getId()?->toRfc4122()]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function renderIndex(array $extra = []): Response
    {
        $counts = $this->completions->countByQuest();
        $rows = [];
        foreach ($this->quests->findBy([], ['availableFrom' => 'DESC']) as $quest) {
            $id = (string) $quest->getId()?->toRfc4122();
            $rows[] = [
                'quest' => $quest,
                'status' => QuestSpecMapper::status($quest),
                'completions' => $counts[$id] ?? 0,
                'locked' => ($counts[$id] ?? 0) > 0,
            ];
        }

        return $this->render('admin/quests/index.html.twig', $extra + [
            'rows' => $rows,
            'csrf' => self::CSRF_ID,
            'import_errors' => [],
            'import_violations' => [],
            'import_json' => '',
        ]);
    }

    private function questOr404(string $id): Quest
    {
        $quest = Uuid::isValid($id) ? $this->quests->find(Uuid::fromString($id)) : null;
        if (!$quest instanceof Quest) {
            throw new NotFoundHttpException('No quest with that id.');
        }

        return $quest;
    }

    private function author(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('No authenticated account; cannot attribute the quest.');
        }

        return $user;
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Replace the stored step rows, the way `lab_quest_write` does: the DELETEs reach the
     * database before the INSERTs, because (quest_id, order) is unique and Doctrine emits
     * INSERTs first within one flush.
     *
     * @param list<QuestStep> $steps
     */
    private function replaceSteps(Quest $quest, array $steps, bool $isNew): void
    {
        if (!$isNew) {
            foreach ($quest->getSteps()->toArray() as $old) {
                $quest->removeStep($old);
            }
            $this->entityManager->flush();
        }
        foreach ($steps as $step) {
            $quest->addStep($step);
            $this->entityManager->persist($step);
        }
    }

    /**
     * The revision name: the month appended, unless it already is. A second revision in the
     * same month would collide with the first, and `lab_quest_read` keys on name, so the
     * collision gets a counter.
     */
    private function revisionName(string $name, ?\DateTimeImmutable $today = null): string
    {
        $suffix = ' (' . ($today ?? new \DateTimeImmutable())->format('Y-m') . ')';
        $base = str_ends_with($name, $suffix) ? $name : $name . $suffix;

        $candidate = $base;
        for ($n = 2; $this->quests->findOneBy(['name' => $candidate]) !== null; ++$n) {
            $candidate = $base . ' ' . $n;
        }

        return $candidate;
    }

    private function floor(Request $request): string
    {
        $floor = trim((string) $request->query->get('floor', ''));
        if ($floor !== '') {
            return $floor;
        }
        try {
            $floor = trim((string) ($this->labConfig->bundle()['floor'] ?? ''));
        } catch (\Throwable) {
            $floor = '';
        }

        return $floor !== '' ? $floor : self::DEFAULT_FLOOR;
    }

    /** @return list<array{key: string, kind: string, room: ?string, x_m: float, y_m: float, synced_at: ?\DateTimeImmutable}> */
    private function mirror(string $floor): array
    {
        return array_map(static fn (LabPlacement $p): array => [
            'key' => $p->getKey(),
            'kind' => $p->getKind(),
            'room' => $p->getRoom(),
            'x_m' => $p->getXM(),
            'y_m' => $p->getYM(),
            'synced_at' => $p->getSyncedAt(),
        ], $this->placements->findByFloor($floor));
    }

    /** @return list<array{name: string, type: string, config: array<string, mixed>}> */
    private function stepsOf(Quest $quest): array
    {
        $steps = $quest->getSteps()->toArray();
        usort($steps, static fn (QuestStep $a, QuestStep $b): int => $a->getOrder() <=> $b->getOrder());

        return array_map(static fn (QuestStep $s): array => [
            'name' => (string) $s->getName(),
            'type' => (string) $s->getType()?->value,
            'config' => $s->getConfig(),
        ], $steps);
    }

    /** @return list<array{type: string, palette: array<string, mixed>, fields: list<array<string, mixed>>}> */
    private function schemas(): array
    {
        $out = [];
        foreach ($this->registry->all() as $schema) {
            $out[] = [
                'type' => $schema->type()->value,
                'palette' => $schema->palette(),
                'fields' => array_map(static fn (FieldSpec $f): array => [
                    'name' => $f->name,
                    'kind' => $f->kind,
                    'required' => $f->required,
                    'min' => $f->min,
                    'max' => $f->max,
                    'choices' => $f->choices,
                    'help' => $f->help,
                ], $schema->fields()),
            ];
        }

        return $out;
    }

    /** @return list<array{name: string, type: string, config: array<string, mixed>}>|null null when not a JSON list */
    private static function decodeSteps(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }

        return array_map(static fn (array $s): array => [
            'name' => $s['name'],
            'type' => $s['type'],
            'config' => $s['config'],
        ], QuestSpecMapper::normaliseSteps($decoded));
    }

    /**
     * The header's four session features onto the first start step's config.
     *
     * @param list<array{name: string, type: string, config: array<string, mixed>}> $steps
     * @param array<string, bool> $features
     * @return list<array{name: string, type: string, config: array<string, mixed>}>
     */
    private static function withFeatures(array $steps, array $features): array
    {
        foreach ($steps as $i => $step) {
            if ($step['type'] === QuestStepType::START->value) {
                $steps[$i]['config']['features'] = $features;
                break;
            }
        }

        return $steps;
    }

    /**
     * @param list<array{name: string, type: string, config: array<string, mixed>}> $steps
     * @return array<string, mixed>
     */
    private static function specFromData(QuestHeaderData $data, array $steps): array
    {
        $spec = [
            'name' => $data->name,
            'description' => $data->description,
            'available_from' => $data->availableFrom?->format(\DateTimeInterface::ATOM),
            'available_to' => $data->availableTo?->format(\DateTimeInterface::ATOM),
            'points' => $data->points,
            'estimated_duration' => $data->estimatedDuration,
            'audience' => $data->audience,
            'steps' => QuestSpecMapper::normaliseSteps($steps),
        ];
        $routes = $data->parsedRoutes();
        if ($routes !== []) {
            $spec['route_pool'] = $routes;
        }

        return $spec;
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
