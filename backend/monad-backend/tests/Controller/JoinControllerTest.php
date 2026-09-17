<?php

namespace App\Tests\Controller;

use App\Entity\BetaSignup;
use App\Enum\BetaSignupStatus;
use App\Join\JoinConsent;
use App\Repository\BetaSignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET and POST /join end to end against a real PostgreSQL (IP-157).
 *
 * Requests go through the booted kernel's handle() rather than a WebTestCase client, because
 * symfony/browser-kit is not installed here and the test needs nothing a crawler adds. The
 * kernel is booted once per test, so the in-memory rate-limiter storage
 * (config/packages/rate_limiter.yaml, when@test) starts empty for every test and is shared by
 * the requests inside one.
 */
class JoinControllerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BetaSignupRepository $signups;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->signups = $container->get(BetaSignupRepository::class);
        $this->em->getConnection()->executeStatement('TRUNCATE beta_signups');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }

    private function get(string $uri): Response
    {
        return self::$kernel->handle(Request::create($uri, 'GET'));
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): Response
    {
        return self::$kernel->handle(Request::create('/join', 'POST', $fields));
    }

    /** @return array<string, string> */
    private static function valid(array $overrides = []): array
    {
        return $overrides + [
            'email' => 'student@example.test',
            'name' => 'Ada',
            'platform' => 'android',
            'availability' => 'sometimes',
            'consent' => '1',
            'updates' => '1',
            'src' => 'discord',
            'website' => '',
        ];
    }

    /** @return list<BetaSignup> */
    private function rows(): array
    {
        $this->em->clear();

        return $this->signups->findBy([], ['createdAt' => 'ASC']);
    }

    public function testGetRendersTheForm(): void
    {
        $response = $this->get('/join?src=poster');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<title>Join the MonadCount beta</title>', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('name="consent"', $html);
        self::assertStringContainsString('name="website"', $html, 'the honeypot field is rendered');
        self::assertStringContainsString('value="poster"', $html, '?src= rides along in the hidden field');
        self::assertStringContainsString(JoinConsent::text(90), $html, 'the consent sentence carries the retention window');
        self::assertStringContainsString('90 days after the invitation', $html);
        self::assertStringContainsString('http://localhost/terms', $html, 'legal links are absolute against DEFAULT_URI');
        self::assertStringNotContainsString('noindex', $html);
    }

    public function testTrailingSlashRedirects(): void
    {
        $response = $this->get('/join/?src=poster');

        self::assertSame(301, $response->getStatusCode());
        self::assertStringEndsWith('/join?src=poster', (string) $response->headers->get('Location'));
    }

    public function testValidPostStoresARowWithTheConsentVersion(): void
    {
        $response = $this->post(self::valid());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Thanks, your interest is recorded.', (string) $response->getContent());
        self::assertStringContainsString('nothing to install yet', (string) $response->getContent());

        $rows = $this->rows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('student@example.test', $row->getEmail());
        self::assertSame('Ada', $row->getName());
        self::assertSame('android', $row->getPlatform()->value);
        self::assertSame('sometimes', $row->getAvailability()->value);
        self::assertTrue($row->isUpdatesOptIn());
        self::assertSame('discord', $row->getSource());
        self::assertSame(JoinConsent::VERSION, $row->getConsentVersion());
        self::assertSame(BetaSignupStatus::NEW, $row->getStatus());
    }

    public function testMissingConsentRerendersWithTheError(): void
    {
        $response = $this->post(self::valid(['consent' => '', 'name' => 'Ada']));

        self::assertSame(422, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('The signup cannot be stored without this.', $html);
        self::assertStringContainsString('value="student@example.test"', $html, 'typed values survive the round trip');
        self::assertStringContainsString('value="Ada"', $html);
        self::assertSame([], $this->rows());
    }

    public function testInvalidEmailAndUnknownChoicesAreRefused(): void
    {
        $response = $this->post(self::valid(['email' => 'not-an-address', 'platform' => 'blackberry', 'availability' => '']));

        self::assertSame(422, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('That does not look like an email address.', $html);
        self::assertStringContainsString('Pick one.', $html);
        self::assertSame([], $this->rows());
    }

    public function testHoneypotRendersTheDonePageAndStoresNothing(): void
    {
        $response = $this->post(self::valid(['website' => 'http://spam.example']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Thanks, your interest is recorded.', (string) $response->getContent());
        self::assertSame([], $this->rows());
    }

    public function testDuplicateEmailIsCaseInsensitiveAndUndisclosed(): void
    {
        $first = $this->post(self::valid());
        $second = $this->post(self::valid(['email' => 'STUDENT@Example.test', 'name' => 'Someone else']));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame((string) $first->getContent(), (string) $second->getContent(), 'the same page for both');

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('Ada', $rows[0]->getName(), 'the first signup is kept as it was');
    }

    public function testSourceIsCleanedNotTrusted(): void
    {
        $this->post(self::valid(['src' => 'poster<script>']));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]->getSource());
    }

    public function testSixthPostInTheWindowIsRateLimited(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $response = $this->post(self::valid(['email' => sprintf('s%d@example.test', $i)]));
            self::assertSame(200, $response->getStatusCode(), "post $i is accepted");
        }

        $sixth = $this->post(self::valid(['email' => 's6@example.test']));

        self::assertSame(429, $sixth->getStatusCode());
        self::assertTrue($sixth->headers->has('Retry-After'));
        $html = (string) $sixth->getContent();
        self::assertStringContainsString('more signups than this page accepts', $html);
        self::assertStringContainsString('name="email"', $html, 'the form is rendered again, not a bare error');
        self::assertCount(5, $this->rows(), 'the sixth submission stored nothing');
    }
}
