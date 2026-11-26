<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The HTTP edge of a live quest: who may call, and what a malformed call costs. */
final class LiveQuestControllerTest extends KernelTestCase
{
    private const EMAIL = 'live-http@example.test';
    private const TOKEN = ['HTTP_X_MONAD_LIVE_TOKEN' => 'test-ingest-token'];

    /** @var array<string, mixed> */
    private array $answer = [];

    protected function setUp(): void
    {
        $_SERVER['MONAD_LIVE_INGEST_TOKEN'] = $_ENV['MONAD_LIVE_INGEST_TOKEN'] = 'test-ingest-token';
        self::bootKernel();
        $this->scrub();
    }

    protected function tearDown(): void
    {
        $this->scrub();
        unset($_SERVER['MONAD_LIVE_INGEST_TOKEN'], $_ENV['MONAD_LIVE_INGEST_TOKEN']);
        parent::tearDown();
    }

    public function testANodeNeedsTheToken(): void
    {
        $body = ['node' => 'monad01', 'rows' => []];

        self::assertSame(403, $this->post('/api/lab/live/sightings', $body));
        self::assertSame(403, $this->post('/api/lab/live/sightings', $body, ['HTTP_X_MONAD_LIVE_TOKEN' => 'wrong']));
        self::assertSame(200, $this->post('/api/lab/live/sightings', $body, self::TOKEN));
    }

    public function testAnUnsetTokenTurnsTheIngestOff(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['MONAD_LIVE_INGEST_TOKEN'] = $_ENV['MONAD_LIVE_INGEST_TOKEN'] = '';
        self::bootKernel();

        self::assertSame(503, $this->post('/api/lab/live/sightings', ['node' => 'monad01', 'rows' => []], ['HTTP_X_MONAD_LIVE_TOKEN' => '']));
    }

    public function testMalformedSightingsAreRefused(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        foreach ([
            ['rows' => []],
            ['node' => 'bad node!', 'rows' => []],
            ['node' => 'monad01', 'rows' => 'x'],
            ['node' => 'monad01', 'rows' => [['not-a-uuid', 1, -60]]],
            ['node' => 'monad01', 'rows' => [[$uuid, 1, 60]]],
            ['node' => 'monad01', 'rows' => [[$uuid, '1', -60]]],
        ] as $body) {
            self::assertSame(400, $this->post('/api/lab/live/sightings', $body, self::TOKEN), json_encode($body));
        }
        // An unknown phone is accepted and dropped.
        self::assertSame(200, $this->post('/api/lab/live/sightings', ['node' => 'monad01', 'rows' => [[$uuid, 1_765_000_000_000, -60]]], self::TOKEN));
        self::assertSame(['kept' => 0], $this->answer);
    }

    public function testASyncNeedsALoginAndAKnownStep(): void
    {
        $path = '/api/quest/live/' . Uuid::v4()->toRfc4122();
        self::assertSame(401, $this->post($path, ['events' => []]));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail(self::EMAIL)->setName('live')->setPassword('x');
        $em->persist($user);
        $em->flush();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get(JWTTokenManagerInterface::class)->create($user)];

        self::assertSame(404, $this->post($path, ['events' => []], $auth));
        self::assertSame(400, $this->post($path, ['events' => [['id' => 'x y', 'kind' => 'scan']]], $auth));
        self::assertSame(400, $this->post($path, ['events' => [], 'subject' => 'nope'], $auth));
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers */
    private function post(string $path, array $body, array $headers = []): int
    {
        $request = Request::create($path, 'POST', server: $headers + ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        $this->answer = (array) json_decode((string) $response->getContent(), true);

        return $response->getStatusCode();
    }

    private function scrub(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('DELETE FROM users WHERE email = ?', [self::EMAIL]);
    }
}
