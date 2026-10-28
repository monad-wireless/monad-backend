<?php

namespace App\Controller;

use App\Service\LabConfigService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The lab surface: what a phone needs in order to play its roles in an experiment.
 *
 * Deliberately not backed by Doctrine. A lab bundle is operator-authored configuration that
 * changes when a rig moves, not user data — putting it behind a schema would mean a migration
 * every time an anchor is added, and the file is the thing an operator actually edits.
 */
class LabController extends AbstractController
{
    public function __construct(
        private LabConfigService $labConfig,
    ) {
    }

    #[Route('/api/lab/config', name: 'api_lab_config', methods: ['GET'])]
    #[OA\Get(
        path: '/api/lab/config',
        summary: 'Lab bundle: collector, access points, beacon plan, traffic profiles',
        description: 'Everything the mobile instrument needs to play the illuminator and witness roles. Authenticated: the bundle carries access-point credentials. The app caches the last good bundle, because a phone joined to an experiment AP normally has no route to the internet.',
        security: [['Bearer' => []]],
        tags: ['Lab']
    )]
    #[OA\Response(response: 200, description: 'Lab bundle')]
    #[OA\Response(response: 401, description: 'Unauthorized')]
    public function config(): JsonResponse
    {
        return $this->json($this->labConfig->bundle(), Response::HTTP_OK);
    }

    #[Route('/api/lab/time', name: 'api_lab_time', methods: ['GET'])]
    #[OA\Get(
        path: '/api/lab/time',
        summary: 'Coarse server time for clock discipline',
        description: 'Returns the server receive and send instants in nanoseconds since the Unix epoch, so a client can run the four-timestamp offset estimate over HTTP. This is a FALLBACK only: the real estimate runs over the same UDP socket as the data stream, because that is the path whose delay actually matters. HTTP adds TLS and keep-alive jitter that the minimum-delay filter cannot remove.',
        security: [['Bearer' => []]],
        tags: ['Lab']
    )]
    #[OA\Response(response: 200, description: 'Server timestamps')]
    public function time(): JsonResponse
    {
        $receive = (int) (microtime(true) * 1_000_000_000);

        return $this->json([
            't2_ns' => $receive,
            't3_ns' => (int) (microtime(true) * 1_000_000_000),
            'source' => 'php/microtime',
            'note' => 'coarse; prefer the UDP exchange on the collector',
        ], Response::HTTP_OK);
    }
}
