<?php

namespace App\Entity;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`users`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'The email {{ value }} is not a valid email address')]
    #[Assert\Length(max: 180, maxMessage: 'Email cannot be longer than {{ limit }} characters')]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Name cannot be longer than {{ limit }} characters')]
    private ?string $name = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Password is required')]
    private ?string $password = null;

    #[ORM\Column(type: 'string', enumType: UserStatus::class)]
    #[Assert\NotNull]
    private UserStatus $status = UserStatus::ACTIVE;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * IP-157 notification preferences. `general` is on once the OS permission is granted;
     * `callouts` is OFF until the user opts in inside the app, because a quest callout is
     * promotional content under App Store Review Guideline 4.5.4 and Google Play's notification
     * policy. Both are also switchable off in the app; the push sender reads them per type.
     */
    #[ORM\Column(name: 'notify_general', type: 'boolean', options: ['default' => true])]
    private bool $notifyGeneral = true;

    #[ORM\Column(name: 'notify_callouts', type: 'boolean', options: ['default' => false])]
    private bool $notifyCallouts = false;

    /** `beta` when the account registered against a beta_signups row, else NULL (IP-157). */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $cohort = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * The EFFECTIVE roles, for the security layer only.
     *
     * ROLE_USER is appended on every read and is not stored, so this is a derived value and never
     * a round-trippable one. Do not bind a form or an admin field to it: the admin's role editor
     * did exactly that, rendered a ROLE_USER tick nobody had set, and then read the same synthetic
     * value back as the "previous" state on save — which made every save look like a removal of a
     * role that was never assigned. Use getAssignedRoles() for anything that edits or displays
     * what this account actually holds.
     *
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * The roles stored on this account, exactly as the database holds them.
     *
     * This is the pair the admin's role editor is bound to. It shows an empty set for a plain
     * participant, because ROLE_USER is implicit and unstored, and an unticked ROLE_USER therefore
     * stays unticked instead of reappearing on the next render.
     *
     * @return list<string>
     */
    public function getAssignedRoles(): array
    {
        return array_values($this->roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setAssignedRoles(array $roles): static
    {
        return $this->setRoles($roles);
    }

    public function setRoles(array $roles): static
    {
        // Reindexed: the column is JSON, and a gappy PHP array serialises as a JSON object rather
        // than an array, which then reads back as something no in_array() caller expects.
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    /**
     * Deliberately NOT named addRole()/removeRole().
     *
     * Symfony's PropertyAccessor prefers a singular adder/remover pair over the setter whenever
     * the value it is asked to write is a list, and it hands that pair whatever the form
     * submitted. The admin's roles field submits strings, so an addRole(UserRole) pair turned
     * every save into "Expected argument of type App\Enum\UserRole, string given at property
     * path roles" — a 500 on user create and on any role edit. Named out of the inflector's way,
     * the accessor falls back to setRoles(array), which is what the form should have been using.
     */
    public function grantRole(UserRole $role): static
    {
        if (!in_array($role->value, $this->roles, true)) {
            $this->roles[] = $role->value;
        }

        return $this;
    }

    public function revokeRole(UserRole $role): static
    {
        $this->roles = array_values(array_filter(
            $this->roles,
            fn($r) => $r !== $role->value
        ));

        return $this;
    }

    public function hasRole(UserRole $role): bool
    {
        return in_array($role->value, $this->getRoles(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SUPERADMIN);
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->status === UserStatus::DELETED;
    }

    public function isNotifyGeneral(): bool
    {
        return $this->notifyGeneral;
    }

    public function setNotifyGeneral(bool $notifyGeneral): static
    {
        $this->notifyGeneral = $notifyGeneral;

        return $this;
    }

    public function isNotifyCallouts(): bool
    {
        return $this->notifyCallouts;
    }

    public function setNotifyCallouts(bool $notifyCallouts): static
    {
        $this->notifyCallouts = $notifyCallouts;

        return $this;
    }

    public function getCohort(): ?string
    {
        return $this->cohort;
    }

    public function setCohort(?string $cohort): static
    {
        $this->cohort = $cohort;

        return $this;
    }

    public function softDelete(): static
    {
        $this->status = UserStatus::DELETED;
        $this->deletedAt = new \DateTimeImmutable();
        $this->email = 'deleted_' . $this->id . '@removed.local';
        $this->name = null;
        $this->password = '';
        $this->roles = [];

        return $this;
    }
}
