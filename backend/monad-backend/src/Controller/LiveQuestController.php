<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Quest\Live\LiveEvent;
use App\Quest\Live\LiveRunException;
use App\Quest\Live\LiveRunService;
use App\Quest\Live\Signal\SightingStore;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** The two ends of a live quest: the phone that plays it and the nodes that hear the phone. */
final class LiveQuestController extends AbstractController
{
    private const MAX_BODY = 256 * 1024;
    private const MAX_ROWS = 5_000;

    /**
     * One sync of a live step. Body: `{events: [{id, kind, data, wall_ms?, mono_ns?}], subject?, broadcasting?}`.
     * `subject` is the service UUID the phone advertises, which is how the nodes know it.
     */
    #[Route('/api/quest/live/{completion}', name: 'api_quest_live_sync', requirements: ['completion' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function sync(string $completion, Request $request, LiveRunService $live): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required'], 401);
        }
        $body = self::body($request);
        if ($body === null || !Uuid::isValid($completion)) {
            return $this->json(['error' => 'Invalid request'], 400);
        }
        $events = [];
        foreach (array_values((array) ($body['events'] ?? [])) as $raw) {
            $event = self::event($raw);
            if ($event === null) {
                return $this->json(['error' => 'Invalid event'], 400);
            }
            $events[] = $event;
        }
        if (count($events) > LiveRunService::MAX_EVENTS) {
            return $this->json(['error' => 'Too many events'], 400);
        }
        $subject = $body['subject'] ?? null;
        if ($subject !== null && (!is_string($subject) || !Uuid::isValid($subject))) {
            return $this->json(['error' => 'Invalid subject'], 400);
        }
        $broadcasting = $body['broadcasting'] ?? null;

        try {
            return $this->json($live->sync(
                $user,
                Uuid::fromString($completion),
                $events,
                $subject === null ? null : strtolower($subject),
                is_bool($broadcasting) ? $broadcasting : null,
            ));
        } catch (LiveRunException $e) {
            return $this->json(['error' => $e->getMessage()], $e->status);
        }
    }

    /**
     * What one node heard. Body: `{node, rows: [[subject, unix_ms, dBm], …]}`.
     *
     * Its own bearer token and no user: a node has no account. An empty token on the server turns
     * the endpoint off rather than open.
     */
    #[Route('/api/lab/live/sightings', name: 'api_lab_live_sightings', methods: ['POST'])]
    public function sightings(
        Request $request,
        SightingStore $sightings,
        ClockInterface $clock,
        #[Autowire('%env(MONAD_LIVE_INGEST_TOKEN)%')]
        string $token,
    ): JsonResponse {
        if ($token === '') {
            return $this->json(['error' => 'Live ingest is off'], 503);
        }
        $given = (string) $request->headers->get('X-Monad-Live-Token', '');
        if (!hash_equals($token, $given)) {
            return $this->json(['error' => 'Forbidden'], 403);
        }
        $body = self::body($request);
        $node = $body['node'] ?? null;
        $raw = $body['rows'] ?? null;
        if (!is_string($node) || preg_match('/^[a-z0-9-]{1,32}$/', $node) !== 1 || !is_array($raw) || count($raw) > self::MAX_ROWS) {
            return $this->json(['error' => 'Invalid request'], 400);
        }
        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row) || count($row) !== 3 || !is_string($row[0]) || !Uuid::isValid($row[0])
                || !is_int($row[1]) || !is_int($row[2]) || $row[2] < -127 || $row[2] > 20) {
                return $this->json(['error' => 'Invalid row'], 400);
            }
            $rows[] = [strtolower($row[0]), $row[1] / 1000, $row[2]];
        }

        return $this->json(['kept' => $sightings->record($node, $rows, (float) $clock->now()->format('U.u'))]);
    }

    /** @return array<string, mixed>|null */
    private static function body(Request $request): ?array
    {
        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY) {
            return null;
        }
        $data = json_decode($content, true, 16);

        return is_array($data) ? $data : null;
    }

    private static function event(mixed $raw): ?LiveEvent
    {
        if (!is_array($raw)) {
            return null;
        }
        $id = $raw['id'] ?? null;
        $kind = $raw['kind'] ?? null;
        $data = $raw['data'] ?? [];
        if (!is_string($id) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1
            || !is_string($kind) || preg_match('/^[a-z_]{1,48}$/', $kind) !== 1 || !is_array($data)) {
            return null;
        }

        return new LiveEvent(
            $id,
            $kind,
            $data,
            is_int($raw['wall_ms'] ?? null) ? $raw['wall_ms'] : null,
            is_int($raw['mono_ns'] ?? null) ? $raw['mono_ns'] : null,
        );
    }
}
