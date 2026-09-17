<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationAudience;
use App\Enum\NotificationType;
use App\Form\NotificationComposeType;
use App\Notification\NotificationAudienceResolver;
use App\Notification\NotificationDispatcher;
use App\Notification\PushSender;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The notifications desk (IP-157, Phase 4): compose, estimate, preview, log, detail, cancel.
 *
 * `#[AdminRoute]` on a plain controller, like the other three desks: EasyAdmin builds the
 * AdminContext so the layout renders, and the class carries no CRUD semantics on purpose.
 * NOTHING IS EDITED AFTER SEND. A sent notification has a detail page and no form; a scheduled,
 * unsent one can be cancelled, which deletes the row (it has no deliveries yet, and a log entry
 * for something that never reached anyone would only confuse the counts).
 *
 * Route order matters under `/people/notifications`: `estimate` is declared before `{id}`, and
 * `{id}` is constrained to a UUID, for the same reason DashboardController::participant() is.
 */
#[IsGranted('ROLE_SUPERADMIN')]
class NotificationsController extends AbstractController
{
    private const UUID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notifications,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly NotificationAudienceResolver $audience,
        private readonly NotificationDispatcher $dispatcher,
        private readonly PushSender $pushSender,
    ) {
    }

    #[AdminRoute(path: '/people/notifications', name: 'people_notifications', options: ['methods' => ['GET', 'POST']])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(NotificationComposeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $notification = $this->build($data);
            $this->entityManager->persist($notification);
            $this->entityManager->flush();

            if ($notification->getScheduledFor() === null) {
                $result = $this->dispatcher->send($notification);
                $this->addFlash('success', sprintf(
                    'Sent to %d inbox%s; push queued for %d, skipped for %d.',
                    $result->inbox,
                    $result->inbox === 1 ? '' : 'es',
                    $result->queued,
                    $result->skipped,
                ));
            } else {
                $this->addFlash('success', sprintf(
                    'Scheduled for %s. The worker sends it within a minute of that instant; cancel it from the log until then.',
                    $notification->getScheduledFor()->format(\DateTimeInterface::ATOM),
                ));
            }

            return $this->redirectToRoute('admin_people_notification', ['id' => $notification->getId()->toRfc4122()]);
        }

        // The estimate is rendered server-side for the values the form holds (defaults, or the
        // rejected submission), and refreshed by admin-notifications.js on a change.
        $type = NotificationType::tryFrom((string) ($form->get('type')->getData() ?? '')) ?? NotificationType::GENERAL;
        $audience = NotificationAudience::tryFrom((string) ($form->get('audience')->getData() ?? '')) ?? NotificationAudience::ALL;
        $estimate = $this->audience->estimate($type, $audience);

        $log = $this->notifications->findLog(200);
        $counts = $this->deliveries->countsForMany($log);

        return $this->render('admin/notifications.html.twig', [
            'form' => $form->createView(),
            'estimate' => $estimate,
            'push' => ['configured' => $this->pushSender->isConfigured(), 'sentence' => $this->pushSender->describe()],
            'log' => $log,
            'counts' => $counts,
            'preview' => [
                'title' => (string) ($form->get('title')->getData() ?? ''),
                'body' => (string) ($form->get('body')->getData() ?? ''),
                'type' => $type->value,
            ],
        ]);
    }

    /** Inbox and push-capable head counts for one (type, audience), for the composer's live estimate. */
    #[AdminRoute(path: '/people/notifications/estimate', name: 'people_notifications_estimate', options: ['methods' => ['GET']])]
    public function estimate(Request $request): JsonResponse
    {
        $type = NotificationType::tryFrom((string) $request->query->get('type', ''));
        $audience = NotificationAudience::tryFrom((string) $request->query->get('audience', ''));
        if ($type === null || $audience === null) {
            return $this->json(['error' => 'type must be general|quest_callout and audience all|beta|operators'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->audience->estimate($type, $audience) + [
            'push_configured' => $this->pushSender->isConfigured(),
        ]);
    }

    /** One notification: what was composed, and every delivery with its push outcome and read mark. */
    #[AdminRoute(path: '/people/notifications/{id}', name: 'people_notification', options: ['methods' => ['GET'], 'requirements' => ['id' => self::UUID]])]
    public function show(string $id): Response
    {
        $notification = $this->find($id);

        return $this->render('admin/notification.html.twig', [
            'n' => $notification,
            'counts' => $this->deliveries->countsFor($notification),
            'deliveries' => $this->deliveries->findForNotification($notification),
            'push' => ['configured' => $this->pushSender->isConfigured(), 'sentence' => $this->pushSender->describe()],
        ]);
    }

    /** Delete a scheduled notification that has not been sent. A sent one is refused: it is history. */
    #[AdminRoute(path: '/people/notifications/{id}/cancel', name: 'people_notification_cancel', options: ['methods' => ['POST'], 'requirements' => ['id' => self::UUID]])]
    public function cancel(string $id, Request $request): Response
    {
        $notification = $this->find($id);
        if (!$this->isCsrfTokenValid('cancel-notification-' . $notification->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The cancel form was stale. Reload and try again.');

            return $this->redirectToRoute('admin_people_notification', ['id' => $id]);
        }
        if ($notification->getSentAt() !== null) {
            $this->addFlash('danger', 'Already sent; nothing is edited after send. Compose a correction instead.');

            return $this->redirectToRoute('admin_people_notification', ['id' => $id]);
        }

        $this->entityManager->remove($notification);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Cancelled "%s". It was never sent.', $notification->getTitle()));

        return $this->redirectToRoute('admin_people_notifications');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function find(string $id): Notification
    {
        $notification = Uuid::isValid($id) ? $this->notifications->find(Uuid::fromString($id)) : null;
        if (!$notification instanceof Notification) {
            throw new NotFoundHttpException('No notification with that id.');
        }

        return $notification;
    }

    /** @param array<string, mixed> $data the validated composer values */
    private function build(array $data): Notification
    {
        $author = $this->getUser();
        if (!$author instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $notification = new Notification(
            NotificationType::from((string) $data['type']),
            trim((string) $data['title']),
            trim((string) $data['body']),
            NotificationAudience::from((string) $data['audience']),
            $author,
        );
        $notification->setQuest($data['quest'] ?? null);
        $deepLink = trim((string) ($data['deep_link'] ?? ''));
        $notification->setDeepLink($deepLink === '' ? null : $deepLink);
        $notification->setPush((bool) ($data['push'] ?? false));
        $notification->setScheduledFor(($data['send_mode'] ?? 'now') === 'schedule' ? $data['scheduled_for'] : null);
        $notification->setExpiresAt($data['expires_at'] ?? null);

        return $notification;
    }
}
