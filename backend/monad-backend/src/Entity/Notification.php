<?php

namespace App\Entity;

use App\Enum\NotificationAudience;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One notification composed in the desk (IP-157).
 *
 * Stored first, always: every notification is served through the inbox endpoint whether or not
 * a push goes out, so a student who denied the OS permission still reads it in the app. The push
 * is a per-recipient decision recorded on NotificationDelivery, gated on a registered token and
 * the type's opt-in. Nothing here is edited after `sent_at` is set; a correction is a new
 * notification.
 *
 * `quest` is optional and SET NULL on delete: a callout outlives the quest it announced, and the
 * log must still show what was sent.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'notification_scheduled_idx', columns: ['scheduled_for'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body;

    #[ORM\ManyToOne(targetEntity: Quest::class)]
    #[ORM\JoinColumn(name: 'quest_id', nullable: true, onDelete: 'SET NULL')]
    private ?Quest $quest = null;

    #[ORM\Column(length: 16, enumType: NotificationAudience::class)]
    private NotificationAudience $audience;

    /** An app route the tap opens, e.g. the quest detail. */
    #[ORM\Column(name: 'deep_link', length: 512, nullable: true)]
    #[Assert\Length(max: 512)]
    private ?string $deepLink = null;

    /** Whether a push is attempted at all; the per-user opt-in still applies on top. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $push = true;

    /** NULL means send now; a future instant is picked up by app:notifications:dispatch-due. */
    #[ORM\Column(name: 'scheduled_for', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledFor = null;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** After this the inbox stops listing it; a callout for a past window is noise. */
    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        NotificationType $type,
        string $title,
        string $body,
        NotificationAudience $audience,
        User $createdBy,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->id = Uuid::v4();
        $this->type = $type;
        $this->title = $title;
        $this->body = $body;
        $this->audience = $audience;
        $this->createdBy = $createdBy;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getQuest(): ?Quest
    {
        return $this->quest;
    }

    public function setQuest(?Quest $quest): static
    {
        $this->quest = $quest;

        return $this;
    }

    public function getAudience(): NotificationAudience
    {
        return $this->audience;
    }

    public function getDeepLink(): ?string
    {
        return $this->deepLink;
    }

    public function setDeepLink(?string $deepLink): static
    {
        $this->deepLink = $deepLink;

        return $this;
    }

    public function isPush(): bool
    {
        return $this->push;
    }

    public function setPush(bool $push): static
    {
        $this->push = $push;

        return $this;
    }

    public function getScheduledFor(): ?\DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function setScheduledFor(?\DateTimeImmutable $scheduledFor): static
    {
        $this->scheduledFor = $scheduledFor;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    /** Set once. A second call is a programming error, because nothing is edited after send. */
    public function markSent(?\DateTimeImmutable $now = null): static
    {
        if ($this->sentAt !== null) {
            throw new \LogicException('Notification already sent; compose a new one instead.');
        }
        $this->sentAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
