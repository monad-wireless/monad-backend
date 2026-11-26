<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Every page in the Lab section carries the section's tab bar.
 *
 * The defect this covers, found on the live console on 2026-09-18: `ui.tabs` was called in
 * `admin/lab.html.twig` and nowhere else, so Arming, Devices, Markers and Bundle rendered with
 * no tab bar at all. A tab was therefore a ONE-WAY EXIT — the only way back into the section was
 * the rail, which returns to Fleet rather than to where the reader was. The bar now lives in
 * `admin/_lab_tabs.html.twig` and every page includes it.
 *
 * THE OTHER FOUR PAGES ARE THE TEST. Asking only for `/admin/lab` would have passed against the
 * broken templates, because that page always had the bar.
 *
 * Driven through `Kernel::handle()` with a seeded session, as DashboardControllerTest and
 * QuestBuilderControllerTest do — symfony/browser-kit is not installed here. This suite creates
 * only its own admin user, and removes it by e-mail prefix, because the test database is shared
 * with the other lanes' suites.
 */
final class LabNavigationTest extends KernelTestCase
{
    private const EMAIL_PREFIX = 'labnav-test-';

    /** The five labels `_lab_tabs.html.twig` renders, in order. */
    private const TAB_LABELS = ['Fleet', 'Arming', 'Devices', 'Markers', 'Bundle'];

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
        $this->em->getConnection()->executeStatement(
            'DELETE FROM users WHERE email LIKE :e',
            ['e' => self::EMAIL_PREFIX . '%'],
        );
    }

    private function makeAdmin(): User
    {
        $user = (new User())
            ->setEmail(self::EMAIL_PREFIX . bin2hex(random_bytes(4)) . '@example.test')
            ->setName('lab navigator')
            ->setPassword('x');
        $user->grantRole(UserRole::SUPERADMIN);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function call(string $uri): Response
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        // `_security_admin` is the firewall name from security.yaml.
        $session->set('_security_admin', serialize(
            new UsernamePasswordToken($this->admin, 'admin', $this->admin->getRoles())
        ));
        $session->save();

        $request = Request::create($uri);
        $request->cookies->set($session->getName(), $session->getId());

        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);
        $this->em->clear();

        return $response;
    }

    /**
     * The Lab section's five destinations. Devices has no route name of its own — it is an
     * EasyAdmin CRUD index — so it is reached through the tab bar's own link rather than a
     * hard-coded query string that would rot the moment the URL scheme changed.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function labPages(): array
    {
        return [
            'Fleet' => ['/admin/lab', 'Fleet'],
            'Arming' => ['/admin/lab/arming', 'Arming'],
            'Markers' => ['/admin/lab/placements', 'Markers'],
            'Bundle' => ['/admin/lab/bundle', 'Bundle'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('labPages')]
    public function testEveryLabPageCarriesTheSectionTabBar(string $uri, string $activeLabel): void
    {
        $response = $this->call($uri);
        self::assertSame(200, $response->getStatusCode(), $uri . ' did not render');

        $html = (string) $response->getContent();
        self::assertStringContainsString('class="ui-tabs"', $html, $uri . ' has no tab bar');

        foreach (self::TAB_LABELS as $label) {
            self::assertStringContainsString('>' . $label . '</a>', $html, $uri . ' is missing the ' . $label . ' tab');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('labPages')]
    public function testEveryLabPageMarksItsOwnTabAsCurrent(string $uri, string $activeLabel): void
    {
        $html = (string) $this->call($uri)->getContent();

        // `ui.tabs` marks the active tab by matching the LABEL, so a typo in the include's
        // `active` argument silently marks nothing. That is exactly what this asserts against.
        self::assertMatchesRegularExpression(
            '~<a[^>]*aria-current="page"[^>]*>' . preg_quote($activeLabel, '~') . '<~',
            $html,
            $uri . ' does not mark ' . $activeLabel . ' as the current tab',
        );
    }

    /** The Devices tab is reachable from the bar, and the page it opens carries the bar too. */
    public function testTheDevicesTabOpensAPageThatKeepsTheTabBar(): void
    {
        $labHtml = (string) $this->call('/admin/lab')->getContent();

        self::assertSame(1, preg_match('~<a href="([^"]+)"[^>]*>Devices</a>~', $labHtml, $m), 'no Devices tab');
        $devicesUrl = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5);

        $response = $this->call($devicesUrl);
        self::assertSame(200, $response->getStatusCode(), $devicesUrl . ' did not render');

        $html = (string) $response->getContent();
        self::assertStringContainsString('class="ui-tabs"', $html, 'the device register lost the tab bar');
        self::assertMatchesRegularExpression('~<a[^>]*aria-current="page"[^>]*>Devices<~', $html);
    }

    /**
     * The arming matrix keeps the hooks admin.css hangs its sideways scroll on.
     *
     * Seventeen columns never fit the card, and on 2026-09-18 the overflow had no affordance
     * (macOS overlay scrollbars) and the quest name scrolled away with the node columns. The fix
     * is CSS, but it is reached through two class names in the template, and a class name is the
     * kind of thing a later edit drops without noticing.
     */
    public function testTheArmingMatrixKeepsItsScrollHooks(): void
    {
        $html = (string) $this->call('/admin/lab/arming')->getContent();

        self::assertStringContainsString('table-responsive matrix-wrap', $html, 'the scroll container lost matrix-wrap');
        self::assertStringContainsString('class="table table-sm table-dense matrix', $html, 'the table lost the matrix class');
    }

    /**
     * `/admin/quests/analytics` does not sit under `/admin/lab/quests`, so the shell's
     * longest-prefix match finds no rail entry for it and used to mark none at all. The template
     * names its section with `mc_active`, the way the per-quest page does.
     */
    public function testQuestAnalyticsMarksARailEntry(): void
    {
        $response = $this->call('/admin/quests/analytics');
        self::assertSame(200, $response->getStatusCode());

        self::assertMatchesRegularExpression(
            '~<nav class="mc-nav"[^>]*>.*?<a[^>]*class="is-active"~s',
            (string) $response->getContent(),
            'no rail entry is marked on the quest analytics page',
        );
    }
}
