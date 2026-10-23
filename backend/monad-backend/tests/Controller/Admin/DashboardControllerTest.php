<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Entity\User;
use App\Enum\QuestStepType;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The Overview page (`/admin`) renders while a quest is live.
 *
 * The defect this covers, found in production on 2026-09-18: `dashboard.html.twig` indexed
 * `quest_runs` — an array `QuestEnrollmentRepository::countsForQuests()` keys by the RFC 4122
 * STRING — with `q.id`, which is a `UuidV4` OBJECT. PHP 8.3 throws on a non-int, non-string
 * array offset, so the whole Overview answered 500 the moment `findCurrentlyAvailable()`
 * returned anything. Every other admin page was unaffected, which is why it went unnoticed.
 *
 * THE LIVE QUEST IS THE TEST. With no live quest the loop body never runs and the page renders
 * whichever way the template is written, so a smoke test that only asked for `/admin` would
 * have passed against the broken template.
 *
 * Driven through `Kernel::handle()` with a seeded session, exactly as QuestBuilderControllerTest
 * does — symfony/browser-kit is not installed here. Rows are removed by name/e-mail prefix in
 * tearDown because the test database is shared with the other lanes' suites.
 */
final class DashboardControllerTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'dash-test-';
    private const QUEST_PREFIX = 'Dash test ';

    private EntityManagerInterface $em;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->scrub();
        $this->admin = $this->makeAdmin();
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

    private function makeAdmin(): User
    {
        $user = (new User())
            ->setEmail(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.test')
            ->setName('overview')
            ->setPassword('x');
        $user->grantRole(UserRole::SUPERADMIN);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Open window, no close date — what `findCurrentlyAvailable()` returns. */
    private function makeLiveQuest(): Quest
    {
        $quest = (new Quest())
            ->setName(self::QUEST_PREFIX . 'live')
            ->setDescription('A quest this test owns.')
            ->setAvailableFrom(new \DateTime('-1 day'))
            ->setPoints(5.0)
            ->setCreatedBy($this->admin);

        foreach ([
            ['Before you start', QuestStepType::START],
            ['Run complete', QuestStepType::FINISH],
        ] as $order => [$name, $type]) {
            $step = (new QuestStep())->setName($name)->setType($type)->setOrder($order)->setConfig([]);
            $quest->addStep($step);
            $this->em->persist($step);
        }

        $this->em->persist($quest);
        $this->em->flush();

        return $quest;
    }

    private function call(string $uri, User $as): Response
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('_security_admin', serialize(new UsernamePasswordToken($as, 'admin', $as->getRoles())));
        $session->save();

        $request = Request::create($uri, 'GET');
        $request->cookies->set($session->getName(), $session->getId());

        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        $this->em->clear();

        return $response;
    }

    public function testTheOverviewRendersWhileAQuestIsLive(): void
    {
        $quest = $this->makeLiveQuest();

        $response = $this->call('/admin', $this->admin);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        // The row itself, not merely a 200: a template that stopped printing live quests
        // would also stop throwing.
        self::assertStringContainsString($quest->getName(), (string) $response->getContent());
    }

    public function testTheOverviewRendersWithNoLiveQuest(): void
    {
        $response = $this->call('/admin', $this->admin);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
