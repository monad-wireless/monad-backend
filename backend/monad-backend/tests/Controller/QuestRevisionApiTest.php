<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use App\Mcp\LabTools;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Quest revisions on the participant and admin API (2026-10-15), over the real kernel, the jwt
 * firewall and PostgreSQL. A run records the revision it was served; the create-only admin API
 * refuses a name that exists instead of making a second quest the app would list.
 */
final class QuestRevisionApiTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'revapi-test-';
    private const QUEST_PREFIX = 'Revision API test ';

    private EntityManagerInterface $em;
    private User $admin;
    private string $jwt;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->scrub();
        $this->admin = (new User())
            ->setEmail(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.test')
            ->setName('revapi')
            ->setPassword('x');
        $this->admin->grantRole(UserRole::SUPERADMIN);
        $this->em->persist($this->admin);
        $this->em->flush();
        $this->jwt = $container->get(JWTTokenManagerInterface::class)->create($this->admin);
        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($this->admin, 'api', $this->admin->getRoles()),
        );
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

    /** @param array<string, mixed>|null $json */
    private function call(string $method, string $uri, ?array $json = null): Response
    {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt,
        ];
        $request = Request::create($uri, $method, [], [], [], $server, $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR));
        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        $this->em->clear();

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private static function steps(string $location): array
    {
        return [
            ['order' => 0, 'name' => 'Before you start', 'type' => 'start', 'config' => ['description' => 'x']],
            ['order' => 1, 'name' => 'Go', 'type' => 'walk_to', 'config' => ['location' => $location, 'description' => 'x']],
            ['order' => 2, 'name' => 'Done', 'type' => 'finish', 'config' => []],
        ];
    }

    private function questId(string $name): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT id FROM quests WHERE name = :n', ['n' => $name]);
    }

    public function testARunRecordsTheRevisionItWasServed(): void
    {
        $tools = static::getContainer()->get(LabTools::class);
        $name = self::QUEST_PREFIX . 'served';
        $tools->questWrite(name: $name, description: 'r1', steps: self::steps('monad07'), available_from: '2026-09-01T00:00:00+00:00');
        $tools->questWrite(name: $name, description: 'r2', steps: self::steps('monad09'));
        $this->em->clear();

        $response = $this->call('POST', '/api/quest/' . $this->questId($name) . '/start');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne(
            'SELECT quest_revision FROM quest_enrollments WHERE quest_id = :q',
            ['q' => $this->questId($name)],
        ));
    }

    public function testTheCreateOnlyAdminApiRefusesATakenName(): void
    {
        $tools = static::getContainer()->get(LabTools::class);
        $name = self::QUEST_PREFIX . 'taken';
        $tools->questWrite(name: $name, description: 'r1', steps: self::steps('monad07'), available_from: '2026-09-01T00:00:00+00:00');
        $this->em->clear();

        $response = $this->call('POST', '/api/admin/quests', [
            'name' => $name,
            'description' => 'a second Counting',
            'available_from' => '2026-09-01T00:00:00+00:00',
            'steps' => self::steps('monad01'),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM quests WHERE name = :n', ['n' => $name]));
    }
}
