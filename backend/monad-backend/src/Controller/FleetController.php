<?php

namespace App\Controller;

use App\Fleet\FleetMetricsReader;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The fleet's public vital signs, for the website to render.
 *
 * This exists so that `monad.dubec.dev` — a host process, and the most exposed
 * thing on the box — never needs a route into the observability stack. Mimir has
 * no auth of its own and publishes no host port; this container is on the same
 * docker bridge, so it reads `mimir:9009` by container DNS and hands over only
 * what `FleetMetricsReader`'s closed allow-list decided to publish.
 *
 * NOT on the public Internet. The `api.monad.dubec.dev` vhost 404s this path,
 * the same treatment `/admin` and `/mcp` get: the website calls it over loopback
 * and an operator reaches it on the tailnet. The data is not secret — the site
 * renders it to anyone — but a metrics surface that the world can poll at
 * whatever rate it likes is a different thing from a cached page, and the
 * asymmetry is free to keep.
 *
 * Unauthenticated by necessity and by design: the site holds no JWT and learns
 * nothing about its readers, so requiring one would mean giving a public,
 * anonymous, cookie-less website a credential to store.
 */
class FleetController extends AbstractController
{
    public function __construct(
        private readonly FleetMetricsReader $fleet,
    ) {
    }

    #[Route('/api/lab/fleet', name: 'api_lab_fleet', methods: ['GET'])]
    #[OA\Get(
        path: '/api/lab/fleet',
        summary: 'Public vital signs for every fleet node',
        description: 'Per-node readings and fleet-wide scalars from the metrics store, as a closed allow-list. `reachable: false` means the store could not be read — which is a different fact from a resting fleet, and the caller is expected to say so rather than render zeros. Not reachable from the public Internet; the site calls it over loopback.',
        tags: ['Lab']
    )]
    #[OA\Response(response: 200, description: 'Fleet snapshot')]
    public function fleet(): JsonResponse
    {
        $snapshot = $this->fleet->snapshot();

        $response = $this->json($snapshot, Response::HTTP_OK);
        // Equal to the reader's own cache TTL: no layer is ever fresher than its
        // source, and the caller caches on the same window.
        $response->headers->set('Cache-Control', 'public, max-age=30');

        return $response;
    }

    /**
     * The same fleet as curves instead of numbers.
     *
     * A separate route rather than a `?history=1` flag on the one above,
     * because the two have different costs and therefore different cache
     * windows: an instant snapshot is thirteen single-point queries, this is
     * three range queries of 121 points each. Folding them into one response
     * would make every live-bar refresh pay for six hours of history it does
     * not draw.
     *
     * Same posture otherwise, and the posture is the point: a closed allow-list
     * of three series, only the `host` label surviving, and an unreadable store
     * reported as `reachable: false` rather than as flat lines at zero.
     */
    #[Route('/api/lab/fleet/history', name: 'api_lab_fleet_history', methods: ['GET'])]
    #[OA\Get(
        path: '/api/lab/fleet/history',
        summary: 'Six hours of per-node curves for the website to draw',
        description: 'Three allow-listed series per node — capture rate, monitor frames, SoC temperature — resampled onto one shared time grid. `from`, `step` and the array index are the whole clock; a step with no sample is `null`, which is a different fact from a zero and must be drawn as a gap. `reachable: false` means the store could not be read. Not reachable from the public Internet; the site calls it over loopback.',
        tags: ['Lab']
    )]
    #[OA\Response(response: 200, description: 'Per-node history')]
    public function history(): JsonResponse
    {
        $history = $this->fleet->history();

        $response = $this->json($history, Response::HTTP_OK);
        // The reader's history TTL, for the same reason as above.
        $response->headers->set('Cache-Control', 'public, max-age=120');

        return $response;
    }
}
