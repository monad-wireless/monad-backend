<?php

declare(strict_types=1);

namespace App\Controller;

use App\Constants\ErrorCode;
use App\Entity\Notification;
use App\Entity\NotificationDelivery;
use App\Entity\PushToken;
use App\Entity\User;
use App\Enum\PushPlatform;
use App\Exception\AuthException;
use App\Exception\ResourceException;
use App\Exception\ValidationException;
use App\Repository\HandsetRepository;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\NotificationRepository;
use App\Repository\PushTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The per-user notification surface (IP-157): inbox, read marks, push-token registration and
 * the two preference toggles. Every handler reads `getUser()` and nothing else, which is why
 * these routes need no line of their own in security.yaml: they fall under `^/api`,
 * IS_AUTHENTICATED_FULLY on the jwt firewall.
 *
 * The wire shapes are the contract the app lane built against on 2026-09-16 and are binding:
 * the inbox is a BARE ARRAY, instants are ISO-8601 with an offset, and PUT preferences answers
 * with the same shape it received.
 */
class MeNotificationsController extends AbstractController
{
    private const TOKEN_MAX = 4096;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notifications,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly PushTokenRepository $tokens,
        private readonly HandsetRepository $handsets,
    ) {
    }

    #[Route('/api/me/notifications', name: 'api_me_notifications', methods: ['GET'])]
    #[OA\Get(
        path: '/api/me/notifications',
        summary: "The signed-in user's inbox",
        description: 'Sent, unexpired notifications addressed to this user, newest first. A bare JSON array. `after` returns only rows whose `sent_at` is strictly later, so the app can fetch the delta since its newest cached row.',
        security: [['Bearer' => []]],
        tags: ['Notifications']
    )]
    #[OA\Parameter(name: 'after', in: 'query', required: false, description: 'ISO-8601 instant, e.g. 2026-09-16T08:00:00Z', schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\Response(
        response: 200,
        description: 'Inbox rows',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string', enum: ['general', 'quest_callout']),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body', type: 'string'),
                    new OA\Property(property: 'quest_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'deep_link', type: 'string', nullable: true),
                    new OA\Property(property: 'sent_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
                ],
                type: 'object'
            )
        )
    )]
    #[OA\Response(response: 400, description: 'VALIDATION_114: after is not an ISO-8601 instant')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function inbox(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $after = null;
        $raw = $request->query->get('after');
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $after = new \DateTimeImmutable(trim($raw));
            } catch (\Exception) {
                throw new ValidationException(ErrorCode::VALIDATION_AFTER_INVALID);
            }
        }

        $rows = [];
        foreach ($this->deliveries->findInbox($user, $after, new \DateTimeImmutable()) as $delivery) {
            $rows[] = self::inboxRow($delivery);
        }

        return $this->json($rows);
    }

    #[Route('/api/me/notifications/{id}/read', name: 'api_me_notification_read', methods: ['POST'])]
    #[OA\Post(
        path: '/api/me/notifications/{id}/read',
        summary: 'Mark one inbox row read',
        description: 'Idempotent: the first call sets read_at, later calls leave it. The body is ignored.',
        security: [['Bearer' => []]],
        tags: ['Notifications']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'The notification id from the inbox', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 204, description: 'Read (now or already)')]
    #[OA\Response(response: 404, description: 'RESOURCE_200: not in this user\'s inbox')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function read(string $id): Response
    {
        $user = $this->requireUser();

        $notification = Uuid::isValid($id) ? $this->notifications->find(Uuid::fromString($id)) : null;
        $delivery = $notification instanceof Notification ? $this->deliveries->findOneForUser($notification, $user) : null;
        if (!$delivery instanceof NotificationDelivery) {
            throw new ResourceException(ErrorCode::RESOURCE_NOT_FOUND);
        }

        $delivery->markRead();
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/me/push-token', name: 'api_me_push_token_put', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/me/push-token',
        summary: 'Register or refresh this installation\'s FCM token',
        description: 'Upsert by token. A token already known is re-parented to the signed-in user (a phone that changed hands), its last_seen_at bumped and any revocation cleared. `handset_id` is the app-minted installation UUID and is resolved to a handset row only when one exists.',
        security: [['Bearer' => []]],
        tags: ['Notifications']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['token', 'platform'],
            properties: [
                new OA\Property(property: 'token', type: 'string', maxLength: 4096),
                new OA\Property(property: 'platform', type: 'string', enum: ['ios', 'android']),
                new OA\Property(property: 'handset_id', type: 'string', nullable: true, description: 'App-minted installation UUID'),
            ]
        )
    )]
    #[OA\Response(response: 204, description: 'Registered')]
    #[OA\Response(response: 400, description: 'VALIDATION_110 / VALIDATION_111 / VALIDATION_112')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function putPushToken(Request $request): Response
    {
        $user = $this->requireUser();
        $data = self::jsonObject($request);

        $token = $data['token'] ?? null;
        if (!is_string($token) || trim($token) === '' || strlen($token) > self::TOKEN_MAX) {
            throw new ValidationException(ErrorCode::VALIDATION_PUSH_TOKEN_REQUIRED);
        }
        $token = trim($token);

        $platform = is_string($data['platform'] ?? null) ? PushPlatform::tryFrom(strtolower(trim($data['platform']))) : null;
        if ($platform === null) {
            throw new ValidationException(ErrorCode::VALIDATION_PUSH_PLATFORM_INVALID);
        }

        $handset = null;
        $handsetId = $data['handset_id'] ?? null;
        if (is_string($handsetId) && trim($handsetId) !== '') {
            $handset = $this->handsets->findByInstallationId(trim($handsetId));
        }

        $row = $this->tokens->findByToken($token);
        if ($row instanceof PushToken) {
            $row->reassign($user);
        } else {
            $row = new PushToken($user, $platform, $token);
            $this->entityManager->persist($row);
        }
        if ($handset !== null) {
            $row->setHandset($handset);
        }
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/me/push-token/{token}', name: 'api_me_push_token_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/me/push-token/{token}',
        summary: 'Forget this installation\'s FCM token',
        description: 'Called on logout and on account deletion. 204 whether or not the token was known, so a best-effort call under a short timeout never has to handle a 404. A token owned by another account is left alone (and still 204).',
        security: [['Bearer' => []]],
        tags: ['Notifications']
    )]
    #[OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 204, description: 'Gone (now or already)')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function deletePushToken(string $token): Response
    {
        $user = $this->requireUser();

        $row = $this->tokens->findByToken($token);
        if ($row instanceof PushToken && $row->getUser()->getId()?->equals($user->getId())) {
            $this->entityManager->remove($row);
            $this->entityManager->flush();
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/me/notification-preferences', name: 'api_me_notification_preferences_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/me/notification-preferences',
        summary: 'The two push opt-ins',
        description: '`notify_general` defaults on; `notify_callouts` defaults OFF and needs the in-app opt-in (quest callouts are promotional under App Store Review Guideline 4.5.4). Neither affects the inbox, only the push.',
        security: [['Bearer' => []]],
        tags: ['Notifications']
    )]
    #[OA\Response(
        response: 200,
        description: 'Preferences',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'notify_general', type: 'boolean'),
                new OA\Property(property: 'notify_callouts', type: 'boolean'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function getPreferences(): JsonResponse
    {
        return $this->json(self::preferences($this->requireUser()));
    }

    #[Route('/api/me/notification-preferences', name: 'api_me_notification_preferences_put', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/me/notification-preferences',
        summary: 'Set the two push opt-ins',
        description: 'Both keys required, both booleans. Answers with the stored state in the same shape.',
        security: [['Bearer' => []]],
        tags: ['Notifications']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['notify_general', 'notify_callouts'],
            properties: [
                new OA\Property(property: 'notify_general', type: 'boolean'),
                new OA\Property(property: 'notify_callouts', type: 'boolean'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Stored preferences',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'notify_general', type: 'boolean'),
                new OA\Property(property: 'notify_callouts', type: 'boolean'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'VALIDATION_110 / VALIDATION_113')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function putPreferences(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = self::jsonObject($request);

        $general = $data['notify_general'] ?? null;
        $callouts = $data['notify_callouts'] ?? null;
        if (!is_bool($general) || !is_bool($callouts)) {
            throw new ValidationException(ErrorCode::VALIDATION_PREFERENCES_MALFORMED);
        }

        $user->setNotifyGeneral($general)->setNotifyCallouts($callouts);
        $this->entityManager->flush();

        return $this->json(self::preferences($user));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AuthException(ErrorCode::AUTH_UNAUTHORIZED);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private static function jsonObject(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        // `{}` decodes to an empty PHP array, which is a list; a non-empty list is a JSON array.
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new ValidationException(ErrorCode::VALIDATION_BODY_NOT_OBJECT);
        }

        return $data;
    }

    /** @return array{notify_general: bool, notify_callouts: bool} */
    private static function preferences(User $user): array
    {
        return [
            'notify_general' => $user->isNotifyGeneral(),
            'notify_callouts' => $user->isNotifyCallouts(),
        ];
    }

    /** @return array<string, mixed> */
    private static function inboxRow(NotificationDelivery $delivery): array
    {
        $n = $delivery->getNotification();

        return [
            'id' => $n->getId()->toRfc4122(),
            'type' => $n->getType()->value,
            'title' => $n->getTitle(),
            'body' => $n->getBody(),
            'quest_id' => $n->getQuest()?->getId()?->toRfc4122(),
            'deep_link' => $n->getDeepLink(),
            'sent_at' => $n->getSentAt()?->format(\DateTimeInterface::ATOM),
            'read_at' => $delivery->getReadAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
