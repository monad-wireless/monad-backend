<?php

namespace App\Entity;

use App\Enum\PushPlatform;
use App\Repository\PushTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One FCM registration token, owned by one user (IP-157).
 *
 * Registered only after login and deleted on logout and on account deletion, so a phone that
 * changes owner does not receive the previous owner's inbox. `handset` is optional provenance
 * (which installation holds the token) and SET NULL on delete because the token outlives the
 * inventory row's usefulness, not the other way round. `revoked_at` is set when FCM reports the
 * token unregistered; a revoked row is kept for the log and skipped by the sender.
 *
 * `token` is UNIQUE across users: FCM issues one token per app installation, so the same string
 * appearing under two accounts means one phone changed hands, and the newer PUT wins.
 */
#[ORM\Entity(repositoryClass: PushTokenRepository::class)]
#[ORM\Table(name: 'push_tokens')]
class PushToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Handset::class)]
    #[ORM\JoinColumn(name: 'handset_id', nullable: true, onDelete: 'SET NULL')]
    private ?Handset $handset = null;

    #[ORM\Column(length: 8, enumType: PushPlatform::class)]
    private PushPlatform $platform;

    #[ORM\Column(type: Types::TEXT, unique: true)]
    private string $token;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, PushPlatform $platform, string $token, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->id = Uuid::v4();
        $this->user = $user;
        $this->platform = $platform;
        $this->token = $token;
        $this->createdAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** The newer registration wins the token (a phone that changed hands). */
    public function reassign(User $user, ?\DateTimeImmutable $now = null): static
    {
        $this->user = $user;
        $this->revokedAt = null;
        $this->lastSeenAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function getHandset(): ?Handset
    {
        return $this->handset;
    }

    public function setHandset(?Handset $handset): static
    {
        $this->handset = $handset;

        return $this;
    }

    public function getPlatform(): PushPlatform
    {
        return $this->platform;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(?\DateTimeImmutable $now = null): static
    {
        $this->lastSeenAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(?\DateTimeImmutable $now = null): static
    {
        $this->revokedAt ??= $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }
}
