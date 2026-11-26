<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Quest;
use App\Entity\QuestEnrollment;
use App\Entity\QuestStep;
use App\Entity\QuestStepCompletion;
use App\Entity\User;
use App\Enum\QuestEnrollmentStatus;
use App\Enum\QuestStepCompletionStatus;
use App\Enum\QuestStepType;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The quest builder over the real kernel, the admin firewall and PostgreSQL (IP-157 Phase 2).
 *
 * Requests go through `Kernel::handle()` rather than a WebTestCase client: symfony/browser-kit is
 * not installed in this repository, and the notification lane's suite drives the kernel the same
 * way. The admin firewall is stateful, so a session is created, a token is written into it under
 * the firewall's key, and the request carries that session cookie — which works because the test
 * environment stores sessions in `session.storage.factory.mock_file`, a real file the next
 * request reads back.
 *
 * Rows this suite creates are removed by name/email prefix in tearDown rather than by truncating
 * the tables: the test database is shared with the other IP-157 lanes' suites.
 */
final class QuestBuilderControllerTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'qb-test-';
    private const QUEST_PREFIX = 'QB test ';

    private EntityManagerInterface $em;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->scrub();
        $this->admin = $this->makeUser(admin: true);
    }

    protected function tearDown(): void
    {
        $this->scrub();
        parent::tearDown();
        $this->em->close();
    }

    private function scrub(): void
    {
        $c = $this->em->getConnection();
        // By enrollment, not by step: a rewritten quest leaves its completions with step_id NULL.
        $c->executeStatement(
            'DELETE FROM quest_step_completions WHERE enrollment_id IN '
            . '(SELECT e.id FROM quest_enrollments e JOIN quests q ON q.id = e.quest_id WHERE q.name LIKE :n)',
            ['n' => self::QUEST_PREFIX . '%'],
        );
        $c->executeStatement(
            'DELETE FROM quest_enrollments WHERE quest_id IN (SELECT id FROM quests WHERE name LIKE :n)',
            ['n' => self::QUEST_PREFIX . '%'],
        );
        $c->executeStatement(
            'DELETE FROM quest_steps WHERE quest_id IN (SELECT id FROM quests WHERE name LIKE :n)',
            ['n' => self::QUEST_PREFIX . '%'],
        );
        $c->executeStatement('DELETE FROM quests WHERE name LIKE :n', ['n' => self::QUEST_PREFIX . '%']);
        $c->executeStatement('DELETE FROM users WHERE email LIKE :e', ['e' => self::EMAIL_PREFIX . '%']);
    }

    private function makeUser(bool $admin): User
    {
        $user = (new User())
            ->setEmail(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.test')
            ->setName('builder')
            ->setPassword('x');
        if ($admin) {
            $user->grantRole(UserRole::SUPERADMIN);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** A quest with a start, one observe and a finish; the shape the builder seeds. */
    private function makeQuest(string $suffix = 'quest'): Quest
    {
        $quest = (new Quest())
            ->setName(self::QUEST_PREFIX . $suffix)
            ->setDescription('A quest this test owns.')
            ->setAvailableFrom(new \DateTime('2026-09-01T00:00:00+00:00'))
            ->setPoints(5.0)
            ->setCreatedBy($this->admin);

        foreach ([
            ['Before you start', QuestStepType::START, []],
            ['Count', QuestStepType::OBSERVE, ['prompt' => 'How many people?', 'min_readings' => 3]],
            ['Run complete', QuestStepType::FINISH, []],
        ] as $order => [$name, $type, $config]) {
            $step = (new QuestStep())->setName($name)->setType($type)->setOrder($order)->setConfig($config);
            $quest->addStep($step);
            $this->em->persist($step);
        }

        $this->em->persist($quest);
        $this->em->flush();

        return $quest;
    }

    private function completeAStepOf(Quest $quest): void
    {
        $enrollment = (new QuestEnrollment())
            ->setUser($this->admin)
            ->setQuest($quest)
            ->setStatus(QuestEnrollmentStatus::IN_PROGRESS);
        $this->em->persist($enrollment);

        $completion = (new QuestStepCompletion())
            ->setEnrollment($enrollment)
            ->setStep($quest->getSteps()->first())
            ->setStatus(QuestStepCompletionStatus::COMPLETED);
        $this->em->persist($completion);
        $this->em->flush();
        // The kernel under test shares this entity manager. A request never has another run's
        // completion managed, so detach it, or Doctrine follows completion->step to the step a
        // rewrite just deleted (QuestWriteOverRunsTest documents the same trap).
        $this->em->detach($completion);
        $this->em->detach($enrollment);
    }

    // ── the session the admin firewall expects ──────────────────────────────────────────────

    /**
     * @param array<string, string> $csrfTokens token id => value, seeded into the session the way
     *                                          SessionTokenStorage stores them ('_csrf/<id>')
     * @return array{0: string, 1: string} the session cookie name and id
     */
    private function signIn(User $user, array $csrfTokens = []): array
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        // `_security_admin` is the firewall name from security.yaml; the ContextListener
        // re-reads the user from app_user_provider by e-mail on the next request.
        $session->set('_security_admin', serialize(new UsernamePasswordToken($user, 'admin', $user->getRoles())));
        foreach ($csrfTokens as $id => $value) {
            $session->set('_csrf/' . $id, $value);
        }
        $session->save();

        return [$session->getName(), $session->getId()];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $csrfTokens
     */
    private function call(string $uri, ?User $as, string $method = 'GET', array $post = [], array $csrfTokens = []): Response
    {
        $request = Request::create($uri, $method, $post);
        if ($as !== null) {
            [$name, $id] = $this->signIn($as, $csrfTokens);
            $request->cookies->set($name, $id);
        }

        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        $this->em->clear();

        return $response;
    }

    // ── the smoke test ──────────────────────────────────────────────────────────────────────

    public function testTheIndexIsTwoHundredForASuperadmin(): void
    {
        $quest = $this->makeQuest();

        $response = $this->call('/admin/lab/quests', $this->admin);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString($quest->getName(), $html);
        // The status word the index computes, the audience, and the import panel.
        self::assertStringContainsString('live', $html);
        self::assertStringContainsString('Import JSON', $html);
    }

    public function testTheIndexRedirectsAnAnonymousVisitorToTheLogin(): void
    {
        $response = $this->call('/admin/lab/quests', null);

        self::assertTrue($response->isRedirection(), 'got ' . $response->getStatusCode());
        self::assertStringContainsString('/admin/login', (string) $response->headers->get('Location'));
    }

    public function testAPlainRoleUserIsRefused(): void
    {
        $response = $this->call('/admin/lab/quests', $this->makeUser(admin: false));

        // The access_control line is ROLE_SUPERADMIN; a signed-in participant gets 403, not the login.
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testTheNewPageRendersTheSeededStartAndFinishSteps(): void
    {
        $response = $this->call('/admin/lab/quests/new', $this->admin);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('id="quest-steps"', $html);
        self::assertStringContainsString('"type":"start"', $html);
        self::assertStringContainsString('"type":"finish"', $html);
        // The island's three seed blocks are all present.
        self::assertStringContainsString('id="step-schemas"', $html);
        self::assertStringContainsString('id="placements"', $html);
        // The one field the island writes. admin-quests.js finds it by
        // `input[name$="[stepsJson]"]`, so this name is the contract between the two.
        self::assertStringContainsString('name="quest_header[stepsJson]"', $html);
        // The palette is rendered from the registry, disabled entry and all.
        self::assertStringContainsString('No access point on this fleet', $html);
    }

    public function testTheEditPageCarriesTheStoredStepsAndThePreflight(): void
    {
        $quest = $this->makeQuest('edit');

        $response = $this->call('/admin/lab/quests/' . $quest->getId()?->toRfc4122() . '/edit', $this->admin);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('How many people?', $html);
        self::assertStringContainsString('id="preflight-server"', $html);
        self::assertStringContainsString('id="quest-steps-island"', $html);
    }

    public function testAnUnknownQuestIsFourOhFour(): void
    {
        $response = $this->call('/admin/lab/quests/' . \Symfony\Component\Uid\Uuid::v4()->toRfc4122() . '/edit', $this->admin);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ── versioning in place (2026-10-15) ────────────────────────────────────────────────────

    public function testAQuestThatHasBeenRunStaysEditableAndOffersNoDuplicate(): void
    {
        $quest = $this->makeQuest('run');
        $this->completeAStepOf($quest);

        $response = $this->call('/admin/lab/quests/' . $quest->getId()?->toRfc4122() . '/edit', $this->admin);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringNotContainsString('data-locked', $html, 'the island has no lock any more');
        self::assertStringContainsString('step completion(s)', $html);
        self::assertStringContainsString('publishes revision 1', $html);
        self::assertStringNotContainsString('Duplicate', $html);
    }

    public function testTheIndexShowsTheRevisionAndNoDuplicateAction(): void
    {
        $quest = $this->makeQuest('indexed');
        $this->completeAStepOf($quest);

        $html = (string) $this->call('/admin/lab/quests', $this->admin)->getContent();

        self::assertStringContainsString($quest->getName(), $html);
        self::assertStringContainsString('Revision', $html);
        self::assertStringNotContainsString('/duplicate', $html);
    }

    public function testTheDuplicateRouteIsGone(): void
    {
        $quest = $this->makeQuest('noduplicate');

        $response = $this->call('/admin/lab/quests/' . $quest->getId()?->toRfc4122() . '/duplicate', $this->admin, 'POST', ['_token' => 'tok'], ['quest_builder' => 'tok']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertCount(1, $this->em->getRepository(Quest::class)->createQueryBuilder('q')
            ->andWhere('q.name LIKE :n')->setParameter('n', self::QUEST_PREFIX . 'noduplicate%')
            ->getQuery()->getResult());
    }

    /**
     * The header form as the builder posts it. `stepsJson` is the island's one hidden field.
     *
     * @param list<array<string, mixed>> $steps
     * @return array<string, array<string, mixed>>
     */
    private static function headerPost(string $name, array $steps, string $token): array
    {
        return ['quest_header' => [
            'name' => $name,
            'description' => 'Edited by the builder test.',
            'audience' => Quest::AUDIENCE_PUBLIC,
            'availableFrom' => '2026-09-01T00:00',
            'availableTo' => '',
            'points' => '5.0',
            'estimatedDuration' => '',
            'recurrenceScope' => 'unlimited',
            'recurrenceCooldownSeconds' => '',
            'routeMode' => 'fixed',
            'routes' => '',
            'stepsJson' => json_encode($steps, JSON_THROW_ON_ERROR),
            '_token' => $token,
        ]];
    }

    public function testSavingAnUnlockedQuestReplacesItsSteps(): void
    {
        $quest = $this->makeQuest('save');
        $id = (string) $quest->getId()?->toRfc4122();

        $response = $this->call('/admin/lab/quests/' . $id . '/edit', $this->admin, 'POST', self::headerPost(
            self::QUEST_PREFIX . 'save renamed',
            [
                ['name' => 'Before you start', 'type' => 'start', 'config' => []],
                ['name' => 'Run complete', 'type' => 'finish', 'config' => []],
            ],
            'tok',
        ), ['quest_header' => 'tok']);

        self::assertTrue($response->isRedirection(), 'a valid save redirects; got ' . $response->getStatusCode());

        $saved = $this->em->getRepository(Quest::class)->find($quest->getId());
        self::assertSame(self::QUEST_PREFIX . 'save renamed', $saved?->getName());
        self::assertCount(2, $saved->getSteps(), 'the observe step was removed by the island');
        self::assertSame(1, $saved->getRevision(), 'the first recorded step set is revision 1');
    }

    public function testSavingTheSameStepsAgainPublishesNoNewRevision(): void
    {
        $quest = $this->makeQuest('same');
        $id = (string) $quest->getId()?->toRfc4122();
        $steps = [
            ['name' => 'Before you start', 'type' => 'start', 'config' => []],
            ['name' => 'Run complete', 'type' => 'finish', 'config' => []],
        ];

        $this->call('/admin/lab/quests/' . $id . '/edit', $this->admin, 'POST', self::headerPost((string) $quest->getName(), $steps, 'tok'), ['quest_header' => 'tok']);
        $this->call('/admin/lab/quests/' . $id . '/edit', $this->admin, 'POST', self::headerPost((string) $quest->getName(), $steps, 'tok'), ['quest_header' => 'tok']);

        self::assertSame(1, $this->em->getRepository(Quest::class)->find($quest->getId())?->getRevision());
    }

    public function testRenamingOntoAnotherQuestsNameIsRefused(): void
    {
        $taken = $this->makeQuest('taken');
        $quest = $this->makeQuest('renamer');
        $id = (string) $quest->getId()?->toRfc4122();

        $response = $this->call('/admin/lab/quests/' . $id . '/edit', $this->admin, 'POST', self::headerPost(
            (string) $taken->getName(),
            [['name' => 'Before you start', 'type' => 'start', 'config' => []]],
            'tok',
        ), ['quest_header' => 'tok']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), 're-rendered with the error');
        self::assertStringContainsString('Another quest has this name', (string) $response->getContent());
        self::assertSame(self::QUEST_PREFIX . 'renamer', $this->em->getRepository(Quest::class)->find($quest->getId())?->getName());
    }

    public function testAStepViolationBlocksTheSaveAndNamesTheStepIndex(): void
    {
        $quest = $this->makeQuest('violation');
        $id = (string) $quest->getId()?->toRfc4122();

        $response = $this->call('/admin/lab/quests/' . $id . '/edit', $this->admin, 'POST', self::headerPost(
            self::QUEST_PREFIX . 'violation renamed',
            [
                ['name' => 'Before you start', 'type' => 'start', 'config' => []],
                ['name' => 'Count', 'type' => 'observe', 'config' => ['prompt' => 'How many?']],
            ],
            'tok',
        ), ['quest_header' => 'tok']);

        // Re-rendered, not redirected: the entity constraint on QuestStep::$config refused step 1.
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('Step config violations; nothing was saved.', $html);
        self::assertStringContainsString('Step 1: Missing required field &quot;min_readings&quot; for step type &quot;observe&quot;.', $html);

        $untouched = $this->em->getRepository(Quest::class)->find($quest->getId());
        self::assertSame(self::QUEST_PREFIX . 'violation', $untouched?->getName());
        self::assertCount(3, $untouched->getSteps());
    }

    public function testAQuestThatHasBeenRunSavesNewStepsAsTheNextRevision(): void
    {
        $quest = $this->makeQuest('runpost');
        $this->completeAStepOf($quest);
        $id = (string) $quest->getId()?->toRfc4122();

        $response = $this->call('/admin/lab/quests/' . $id . '/edit', $this->admin, 'POST', self::headerPost(
            (string) $quest->getName(),
            [['name' => 'Before you start', 'type' => 'start', 'config' => []]],
            'tok',
        ), ['quest_header' => 'tok']);

        self::assertTrue($response->isRedirection(), 'the save succeeds; got ' . $response->getStatusCode());

        $saved = $this->em->getRepository(Quest::class)->find($quest->getId());
        self::assertCount(1, $saved?->getSteps(), 'the steps are replaced in place');
        self::assertSame(1, $saved->getRevision());
        self::assertCount(1, $this->em->getRepository(Quest::class)->findBy(['name' => $quest->getName()]), 'still one quest');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM quest_step_completions c JOIN quest_enrollments e ON e.id = c.enrollment_id WHERE e.quest_id = :q',
            ['q' => (string) $quest->getId()],
        ), 'the run survives the rewrite');
    }

    // ── hide ────────────────────────────────────────────────────────────────────────────────

    public function testHideWithoutACsrfTokenIsRefusedAndTheWindowIsUntouched(): void
    {
        $quest = $this->makeQuest('hide');
        $id = (string) $quest->getId()?->toRfc4122();

        $response = $this->call('/admin/lab/quests/' . $id . '/hide', $this->admin, 'POST');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertNull($this->em->getRepository(Quest::class)->find($quest->getId())?->getAvailableTo());
    }

    public function testHideSetsAvailableToNow(): void
    {
        $quest = $this->makeQuest('hidenow');
        $id = (string) $quest->getId()?->toRfc4122();

        $response = $this->call('/admin/lab/quests/' . $id . '/hide', $this->admin, 'POST', ['_token' => 'tok'], ['quest_builder' => 'tok']);

        self::assertTrue($response->isRedirection());
        $hidden = $this->em->getRepository(Quest::class)->find($quest->getId());
        self::assertNotNull($hidden?->getAvailableTo());
        self::assertSame('hidden', \App\Quest\QuestSpecMapper::status($hidden, new \DateTimeImmutable('+1 minute')));
    }

    // ── import ──────────────────────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $steps */
    private function importPayload(string $name, array $steps): string
    {
        return json_encode([
            'name' => $name,
            'description' => 'Imported by the builder test.',
            'available_from' => '2026-09-15T00:00:00+00:00',
            'available_to' => '2027-06-30T23:59:00+00:00',
            'points' => 10.0,
            'estimated_duration' => 12,
            'audience' => Quest::AUDIENCE_PUBLIC,
            'steps' => $steps,
        ], JSON_THROW_ON_ERROR);
    }

    public function testImportCreatesAQuestFromALabQuestWritePayload(): void
    {
        $name = self::QUEST_PREFIX . 'imported';

        $response = $this->call('/admin/lab/quests/import', $this->admin, 'POST', [
            '_token' => 'tok',
            'json' => $this->importPayload($name, [
                ['order' => 0, 'name' => 'Before you start', 'type' => 'start', 'config' => ['features' => ['broadcast' => true]]],
                ['order' => 1, 'name' => 'Count', 'type' => 'observe', 'config' => ['prompt' => 'How many?', 'min_readings' => 4]],
                ['order' => 2, 'name' => 'Run complete', 'type' => 'finish', 'config' => []],
            ]),
        ], ['quest_builder' => 'tok']);

        self::assertTrue($response->isRedirection(), 'got ' . $response->getStatusCode());

        $quest = $this->em->getRepository(Quest::class)->findOneBy(['name' => $name]);
        self::assertNotNull($quest);
        self::assertCount(3, $quest->getSteps());
        self::assertSame(10.0, $quest->getPoints());
        self::assertSame(12, $quest->getEstimatedDuration());
    }

    public function testImportReplacesAQuestOfTheSameNameThatHasNoCompletions(): void
    {
        $quest = $this->makeQuest('replaced');

        $response = $this->call('/admin/lab/quests/import', $this->admin, 'POST', [
            '_token' => 'tok',
            'json' => $this->importPayload((string) $quest->getName(), [
                ['order' => 0, 'name' => 'Before you start', 'type' => 'start', 'config' => []],
                ['order' => 1, 'name' => 'Run complete', 'type' => 'finish', 'config' => []],
            ]),
        ], ['quest_builder' => 'tok']);

        self::assertTrue($response->isRedirection());
        self::assertCount(2, $this->em->getRepository(Quest::class)->find($quest->getId())?->getSteps());
        self::assertCount(1, $this->em->getRepository(Quest::class)->findBy(['name' => $quest->getName()]));
    }

    public function testImportReplacesAQuestThatHasBeenRunAsItsNextRevision(): void
    {
        $quest = $this->makeQuest('imported-over-runs');
        $this->completeAStepOf($quest);

        $response = $this->call('/admin/lab/quests/import', $this->admin, 'POST', [
            '_token' => 'tok',
            'json' => $this->importPayload((string) $quest->getName(), [
                ['order' => 0, 'name' => 'Before you start', 'type' => 'start', 'config' => []],
            ]),
        ], ['quest_builder' => 'tok']);

        self::assertTrue($response->isRedirection(), 'got ' . $response->getStatusCode());
        $saved = $this->em->getRepository(Quest::class)->find($quest->getId());
        self::assertCount(1, $saved?->getSteps());
        self::assertSame(1, $saved->getRevision());
        self::assertSame('import', $this->em->getConnection()->fetchOne(
            'SELECT source FROM quest_revisions WHERE quest_id = :q AND revision = 1',
            ['q' => (string) $quest->getId()],
        ));
    }

    public function testImportShowsTheStepViolationsAndWritesNothing(): void
    {
        $name = self::QUEST_PREFIX . 'refused';

        $response = $this->call('/admin/lab/quests/import', $this->admin, 'POST', [
            '_token' => 'tok',
            'json' => $this->importPayload($name, [
                ['order' => 0, 'name' => 'Count', 'type' => 'observe', 'config' => ['prompt' => 'How many?']],
            ]),
        ], ['quest_builder' => 'tok']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Step 0 (observe): Missing required field', (string) $response->getContent());
        self::assertNull($this->em->getRepository(Quest::class)->findOneBy(['name' => $name]));
    }

    public function testImportOfSomethingThatIsNotAQuestSaysSo(): void
    {
        $response = $this->call('/admin/lab/quests/import', $this->admin, 'POST', [
            '_token' => 'tok',
            'json' => '[1, 2, 3]',
        ], ['quest_builder' => 'tok']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('The payload is not a JSON object', (string) $response->getContent());
    }

    // ── export ──────────────────────────────────────────────────────────────────────────────

    public function testExportIsTheLabQuestWriteShapeAsADownload(): void
    {
        $quest = $this->makeQuest('export');

        $response = $this->call('/admin/lab/quests/' . $quest->getId()?->toRfc4122() . '/export', $this->admin);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('.json', (string) $response->headers->get('Content-Disposition'));

        $spec = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($quest->getName(), $spec['name']);
        self::assertSame(['name', 'description', 'available_from', 'available_to', 'points', 'estimated_duration', 'audience', 'steps'], array_keys($spec));
        self::assertCount(3, $spec['steps']);
        self::assertSame('observe', $spec['steps'][1]['type']);
        self::assertSame(['prompt' => 'How many people?', 'min_readings' => 3], $spec['steps'][1]['config']);
        // route_pool is absent rather than null: the shape lab_quest_write accepts back.
        self::assertArrayNotHasKey('route_pool', $spec);
    }

    public function testExportIsRefusedToAnAnonymousVisitor(): void
    {
        $quest = $this->makeQuest('private');

        $response = $this->call('/admin/lab/quests/' . $quest->getId()?->toRfc4122() . '/export', null);

        self::assertTrue($response->isRedirection());
    }
}
