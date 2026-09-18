<?php

namespace App\Entity;

use App\Enum\PushStatus;
use App\Repository\NotificationDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One recipient of one notification (IP-157): the inbox row and the push outcome in one place.
 *
 * `push_status` records the worker's decision for THIS user, so "why did I not get a push" has
 * an answer per person: skipped (no token, no permission, opt-in off), queued, sent, failed with
 * `push_error`. `read_at` is the inbox mark, set idempotently by POST /api/me/notifications/{id}/read.
 *
 * UNIQUE (notification_id, user_id): a recipient is counted once however many times the audience
 * is resolved. CASCADE on both sides: a delivery has no meaning without either end, and
 * anonymising a user (User::softDelete keeps the row) is not a delete.
 */
#[ORM\Entity(repositoryClass: NotificationDeliveryRepository::class)]
#[ORM\Table(name: 'notification_deliveries')]
#[ORM\UniqueConstraint(name: 'notification_deliveries_notification_id_user_id_key', columns: ['notification_id', 'user_id'])]
class NotificationDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Notification::class)]
    #[ORM\JoinColumn(name: 'notification_id', nullable: false, onDelete: 'CASCADE')]
    private Notification $notification;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'push_status', length: 16, enumType: PushStatus::class)]
    private PushStatus $pushStatus;

    #[ORM\Column(name: 'push_error', type: Types::TEXT, nullable: true)]
    private ?string $pushError = null;

    /** When FCM accepted the message; NULL for skipped, queued and failed. */
    #[ORM\Column(name: 'delivered_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(name: 'read_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(Notification $notification, User $user, PushStatus $pushStatus = PushStatus::SKIPPED)
    {
        $this->id = Uuid::v4();
        $this->notification = $notification;
        $this->user = $user;
        $this->pushStatus = $pushStatus;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPushStatus(): PushStatus
    {
        return $this->pushStatus;
    }

    public function getPushError(): ?string
    {
        return $this->pushError;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markQueued(): static
    {
        $this->pushStatus = PushStatus::QUEUED;

        return $this;
    }

    public function markSent(?\DateTimeImmutable $now = null): static
    {
        $this->pushStatus = PushStatus::SENT;
        $this->pushError = null;
        $this->deliveredAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function markFailed(string $error): static
    {
        $this->pushStatus = PushStatus::FAILED;
        $this->pushError = $error;

        return $this;
    }

    /** Idempotent: the first read wins, a second tap does not move the instant. */
    public function markRead(?\DateTimeImmutable $now = null): static
    {
        $this->readAt ??= $now ?? new \DateTimeImmutable();

        return $this;
    }
}
