<?php

namespace App\Entity;

use App\Enum\BetaAvailability;
use App\Enum\BetaPlatform;
use App\Enum\BetaSignupStatus;
use App\Repository\BetaSignupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One request to join the beta, from `/join` (IP-157).
 *
 * PII with a stated purpose and retention: the consent text version shown is stored beside the
 * instant of consent, and `app:beta:purge` removes rows that never registered a configurable
 * number of days after invitation. Email is unique CASE-INSENSITIVELY through a functional index
 * on lower(email) that the migration owns; Doctrine cannot express it in mapping, so there is no
 * `unique: true` on the column and callers compare lower-cased.
 *
 * `withdraw()` scrubs email, name and notes in place and keeps every date, the posture of
 * User::softDelete(): the funnel still counts the row, nobody can be identified from it.
 *
 * Status pipeline: new -> invited -> registered; declined and withdrawn are terminal side exits.
 * `registered` is written by POST /api/auth/register, never by the desk.
 */
#[ORM\Entity(repositoryClass: BetaSignupRepository::class)]
#[ORM\Table(name: 'beta_signups')]
#[ORM\Index(name: 'beta_signup_status_idx', columns: ['status'])]
class BetaSignup
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $name = null;

    #[ORM\Column(length: 8, enumType: BetaPlatform::class)]
    private BetaPlatform $platform;

    #[ORM\Column(length: 12, enumType: BetaAvailability::class)]
    private BetaAvailability $availability;

    #[ORM\Column(name: 'consent_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $consentAt;

    /** The version of the consent text the applicant saw, e.g. `2026-09`. */
    #[ORM\Column(name: 'consent_version', length: 16)]
    private string $consentVersion;

    #[ORM\Column(name: 'updates_opt_in', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $updatesOptIn = false;

    /** `?src=` on /join, e.g. `poster` or `discord`. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(length: 12, enumType: BetaSignupStatus::class)]
    private BetaSignupStatus $status = BetaSignupStatus::NEW;

    #[ORM\Column(name: 'invited_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invitedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invited_by_id', nullable: true)]
    private ?User $invitedBy = null;

    #[ORM\Column(name: 'registered_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Operator notes; scrubbed on withdraw. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'withdrawn_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    public function __construct(
        string $email,
        BetaPlatform $platform,
        BetaAvailability $availability,
        string $consentVersion,
        ?\DateTimeImmutable $now = null,
    ) {
        $now ??= new \DateTimeImmutable();
        $this->id = Uuid::v4();
        $this->email = trim($email);
        $this->platform = $platform;
        $this->availability = $availability;
        $this->consentVersion = $consentVersion;
        $this->consentAt = $now;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = ($name === null || trim($name) === '') ? null : trim($name);

        return $this;
    }

    public function getPlatform(): BetaPlatform
    {
        return $this->platform;
    }

    public function getAvailability(): BetaAvailability
    {
        return $this->availability;
    }

    public function getConsentAt(): \DateTimeImmutable
    {
        return $this->consentAt;
    }

    public function getConsentVersion(): string
    {
        return $this->consentVersion;
    }

    public function isUpdatesOptIn(): bool
    {
        return $this->updatesOptIn;
    }

    public function setUpdatesOptIn(bool $updatesOptIn): static
    {
        $this->updatesOptIn = $updatesOptIn;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getStatus(): BetaSignupStatus
    {
        return $this->status;
    }

    public function getInvitedAt(): ?\DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function getRegisteredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getWithdrawnAt(): ?\DateTimeImmutable
    {
        return $this->withdrawnAt;
    }

    /** new -> invited. The desk's "mark invited", beside the mailto: it opens. */
    public function markInvited(User $by, ?\DateTimeImmutable $now = null): static
    {
        $this->assertStatus([BetaSignupStatus::NEW], 'invite');
        $this->status = BetaSignupStatus::INVITED;
        $this->invitedAt = $now ?? new \DateTimeImmutable();
        $this->invitedBy = $by;

        return $this;
    }

    /** new|invited -> registered. Written by POST /api/auth/register when the emails match. */
    public function markRegistered(User $user, ?\DateTimeImmutable $now = null): static
    {
        $this->assertStatus([BetaSignupStatus::NEW, BetaSignupStatus::INVITED], 'register');
        $this->status = BetaSignupStatus::REGISTERED;
        $this->registeredAt = $now ?? new \DateTimeImmutable();
        $this->user = $user;

        return $this;
    }

    /** new|invited -> declined. Keeps the row readable; the applicant is told nothing. */
    public function markDeclined(): static
    {
        $this->assertStatus([BetaSignupStatus::NEW, BetaSignupStatus::INVITED], 'decline');
        $this->status = BetaSignupStatus::DECLINED;

        return $this;
    }

    /**
     * Scrub in place. Email, name and notes go; every date stays so the funnel still counts the
     * row. Allowed from any non-registered status, because a registered account is anonymised
     * through User::softDelete() instead.
     */
    public function withdraw(?\DateTimeImmutable $now = null): static
    {
        $this->assertStatus([BetaSignupStatus::NEW, BetaSignupStatus::INVITED, BetaSignupStatus::DECLINED], 'withdraw');
        $this->status = BetaSignupStatus::WITHDRAWN;
        $this->withdrawnAt = $now ?? new \DateTimeImmutable();
        // `.invalid` is reserved by RFC 2606 and resolves nowhere, so a scrubbed row can never be
        // mailed by accident. The eight hex digits come from the row's own id: deterministic, so
        // a re-run of the purge is a no-op, and distinct enough that the unique index on
        // lower(email) stays satisfied at any volume this desk will ever see.
        $this->email = 'withdrawn-' . substr(str_replace('-', '', $this->id->toRfc4122()), 0, 8) . '@invalid';
        $this->name = null;
        $this->notes = null;

        return $this;
    }

    /** @param list<BetaSignupStatus> $allowed */
    private function assertStatus(array $allowed, string $action): void
    {
        if (!in_array($this->status, $allowed, true)) {
            throw new \LogicException(sprintf('Cannot %s a beta signup in status "%s".', $action, $this->status->value));
        }
    }
}
