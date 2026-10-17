<?php

namespace App\Controller;

use App\Entity\BetaSignup;
use App\Join\JoinConsent;
use App\Join\JoinSubmission;
use App\Repository\BetaSignupRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The beta signup page (IP-157): `monad.dubec.dev/join`, served by this API through one nginx
 * location on the site's vhost.
 *
 * Public, form-encoded, no login and no CSRF: there is no session to ride. Protection is a
 * honeypot field (`website`, hidden by CSS, must stay empty) and a sliding-window rate limit per
 * client IP (`join` in config/packages/rate_limiter.yaml). A honeypot hit and a duplicate email
 * both render the same confirmation page as a real signup, so the page discloses nothing about
 * which addresses it already knows.
 *
 * Links to /terms and /privacy-policy are built against DEFAULT_URI, the API's own canonical
 * base, rather than the request host: the request arrives with `Host: monad.dubec.dev`, and the
 * site has no legal pages, so a request-relative link would 404.
 */
final class JoinController extends AbstractController
{
    public function __construct(
        private readonly BetaSignupRepository $signups,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactoryInterface $joinLimiter,
        private readonly int $retentionDays,
        private readonly string $apiBaseUrl,
        private readonly string $publicSiteUrl,
    ) {
    }

    /**
     * Declared BEFORE the `/join/` route below, and that order is load-bearing: Symfony matches
     * the collection in declaration order and tolerates a missing trailing slash, so a `/join/`
     * route listed first would answer `GET /join` with a 301 to itself and the two routes would
     * bounce a browser between them.
     */
    #[Route('/join', name: 'join', methods: ['GET', 'POST'])]
    public function join(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->form([
                'src' => JoinSubmission::cleanSource($request->query->getString('src')),
                'consent' => false,
                'updates' => false,
            ], []);
        }

        $limit = $this->joinLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $response = $this->form([], [], limited: true);
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
            $response->headers->set('Retry-After', (string) max(1, $retryAfter));

            return $response;
        }

        $submission = JoinSubmission::fromRequest($request);
        if ($submission->honeypotFilled) {
            return $this->done();
        }
        if (!$submission->isValid()) {
            $response = $this->form($submission->values(), $submission->errors);
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

            return $response;
        }

        if ($this->signups->findByEmail($submission->email) !== null) {
            return $this->done();
        }

        $signup = (new BetaSignup($submission->email, $submission->platform, $submission->availability, JoinConsent::VERSION))
            ->setName($submission->name)
            ->setUpdatesOptIn($submission->updates)
            ->setSource($submission->source);
        try {
            $this->em->persist($signup);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two submissions of one address raced past the lookup above; the index on
            // lower(email) held, and the applicant sees the same page either way.
        }

        return $this->done();
    }

    /**
     * `/join/` is the address people type; it is one page, so the slash form is a permanent
     * redirect rather than a second rendering. `?src=` rides along, because a poster QR that
     * ends in a slash must still tag its signups.
     */
    #[Route('/join/', name: 'join_slash', methods: ['GET'])]
    public function slash(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('join', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(array $values, array $errors, bool $limited = false): Response
    {
        return $this->render('join/form.html.twig', $this->page() + [
            'values' => $values,
            'errors' => $errors,
            'limited' => $limited,
            'consent_text' => JoinConsent::text($this->retentionDays),
            'consent_version' => JoinConsent::VERSION,
        ]);
    }

    private function done(): Response
    {
        return $this->render('join/done.html.twig', $this->page());
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        $base = rtrim($this->apiBaseUrl, '/');

        return [
            'retention_days' => $this->retentionDays,
            'join_url' => rtrim($this->publicSiteUrl, '/') . $this->generateUrl('join'),
            'terms_url' => $base . $this->generateUrl('terms'),
            'privacy_url' => $base . $this->generateUrl('privacy_policy'),
        ];
    }
}
