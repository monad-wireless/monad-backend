<?php

namespace App\Controller;

use App\Dto\Lab\MarkerIndexResponseDto;
use App\Service\MarkerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Markers: rendered on demand, and — since IP-129 — listed for the public landing page.
 *
 * Rendering stays authenticated like the rest of /api: producing a marker image is producing the
 * thing that advances a step, so it is not a public capability. The admin print sheet and any MCP
 * client both come through here, which is what makes "the code on the wall" and "the string in
 * the quest" the same thing by construction.
 *
 * The index is public and read-only, because 28 printed cards point a stranger's camera at
 * `https://monad.dubec.dev/m/<code>` and that page has to be able to say whether the card in
 * their hand is doing anything. It publishes a projection (MarkerIndexResponseDto), never a
 * step's `config` and never a placement.
 */
class MarkerController extends AbstractController
{
    public function __construct(
        private readonly MarkerService $markers,
    ) {
    }

    /**
     * Every live marker, with the quests that ask for it.
     *
     * A collection rather than a per-code lookup on purpose: matching a scanned code against an
     * `expected_value` means folding both to their trailing path segment (the payload is a full
     * URL, two quests still carry a bare code), and that rule belongs in exactly one place. One
     * fetch then serves every scan in the portal's 30 s window, which is the load shape — a group
     * arriving together scans different cards in the same minute.
     */
    #[Route('/api/lab/marker-index', name: 'api_lab_marker_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $response = $this->json((new MarkerIndexResponseDto($this->markers->markers()))->toArray());
        // Same short, uniform cache as the device endpoint: the portal page in front of this is
        // the load-shedding layer, and a group scanning at once must collapse to one read.
        $response->headers->set('Cache-Control', 'public, max-age=30');

        return $response;
    }

    #[Route('/api/lab/markers/{value}.svg', name: 'api_lab_marker_svg', methods: ['GET'], requirements: ['value' => '.+'])]
    public function svg(string $value): Response
    {
        return new Response($this->markers->svg($value), Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            // A marker is a pure function of its payload, so it caches forever; the payload
            // changing means a different URL.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    #[Route('/api/lab/markers/{value}.png', name: 'api_lab_marker_png', methods: ['GET'], requirements: ['value' => '.+'])]
    public function png(string $value): Response
    {
        return new Response($this->markers->png($value), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
