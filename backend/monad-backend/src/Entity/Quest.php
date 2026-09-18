<?php

namespace App\Entity;

use App\Quest\RecurrencePolicy;
use App\Repository\QuestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: QuestRepository::class)]
#[ORM\Table(name: 'quests')]
#[ORM\Index(name: 'quest_audience_idx', columns: ['audience'])]
#[ORM\HasLifecycleCallbacks]
class Quest
{
    /** Offered to anyone. The default, and what every pre-IP-145 quest means. */
    public const AUDIENCE_PUBLIC = 'public';

    /** Offered only to an operator. Filtered from the listing, refused on start. */
    public const AUDIENCE_OPERATOR = 'operator';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Quest name is required')]
    #[Assert\Length(max: 255, maxMessage: 'Quest name cannot be longer than {{ limit }} characters')]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Quest description is required')]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Assert\NotNull(message: 'Available from date is required')]
    private ?\DateTimeInterface $availableFrom = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $availableTo = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Quest creator is required')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\NotNull(message: 'Points value is required')]
    #[Assert\PositiveOrZero(message: 'Points must be a positive number or zero')]
    private float $points = 0.0;

    /**
     * Capability tokens a device must satisfy to be offered this quest.
     *
     * A quest needing a sensor the handset lacks is not a degraded run — it is a run that looks
     * complete and is missing the measurement. So the catalogue filters on this rather than letting
     * the app discover the gap halfway through.
     *
     * Free-form strings on purpose: adding a sensor is a token on the device plus a token here, not
     * a schema migration.
     *
     * @var string[]
     */
    #[ORM\Column(name: 'required_capabilities', type: Types::JSON)]
    private array $requiredCapabilities = [];

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Positive(message: 'Estimated duration must be a positive number')]
    private ?int $estimatedDuration = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $featuredImage = null;

    #[ORM\OneToMany(targetEntity: QuestStep::class, mappedBy: 'quest', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['order' => 'ASC'])]
    private Collection $steps;

    /**
     * How often one participant may repeat this quest (IP-128).
     *
     * NULL means UNLIMITED, which is the behaviour every quest has today —
     * `Version20251202185420` dropped the unique enrollment index and
     * `startQuest()` performs no enrollment lookup, so nothing has ever prevented
     * a replay. A cooldown is therefore opt-in per quest, authored in `/admin`,
     * and there is deliberately no system-wide default: a measurement quest wants
     * none (a pre-registered session runs the same nodes repeatedly in one
     * afternoon), while an evergreen "collect the fleet" quest wants one.
     *
     * Shape: `{"scope": "per_device"|"per_quest", "cooldown_seconds": int}`.
     * Read through {@see \App\Quest\RecurrencePolicy::fromArray()}, which returns
     * null for anything malformed rather than throwing — a bad policy must
     * degrade to "no extra gate", never take the quest catalogue down.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $recurrence = null;

    /**
     * Who this quest is offered to (IP-145).
     *
     * `public` is the default and is what every quest written before IP-145 means.
     * `operator` is filtered OUT of the listing rather than refused with a reason:
     * a reason discloses that a withheld quest exists, and filtering leaks nothing.
     * The start endpoint checks it too, because a filtered list is a convenience
     * and never the authorisation.
     */
    #[ORM\Column(length: 16, options: ['default' => Quest::AUDIENCE_PUBLIC])]
    #[Assert\Choice(choices: [Quest::AUDIENCE_PUBLIC, Quest::AUDIENCE_OPERATOR])]
    private string $audience = self::AUDIENCE_PUBLIC;

    /**
     * How the route may vary between enrollments (IP-145).
     *
     * NULL and `{"mode": "fixed"}` are the same thing: serve the declared step order.
     * `{"mode": "pool", "routes": [[key, ...], ...]}` carries routes that were
     * generated by `monad-knowledge lab quest-build`, never computed here.
     *
     * That is deliberate rather than lazy. The rule that keeps a leg out of a wall
     * reads PostGIS, which this backend cannot see, and a second implementation of
     * it would diverge silently because an unwalkable leg still renders as a short
     * line on a plan. This entity picks an element of a list and nothing more.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'route_policy', type: Types::JSON, nullable: true)]
    private ?array $routePolicy = null;

    /**
     * Which physical nodes offer this quest (IP-128).
     *
     * EMPTY MEANS EVERY NODE, not "none" — that is what every quest written
     * before IP-128 means, and treating empty as "nowhere" would silently
     * unpublish the entire existing catalogue on migration.
     *
     * @var Collection<int, Device>
     */
    #[ORM\ManyToMany(targetEntity: Device::class)]
    #[ORM\JoinTable(name: 'quest_devices')]
    #[ORM\JoinColumn(name: 'quest_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'device_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['slug' => 'ASC'])]
    private Collection $armedDevices;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->steps = new ArrayCollection();
        $this->armedDevices = new ArrayCollection();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getAvailableFrom(): ?\DateTimeInterface
    {
        return $this->availableFrom;
    }

    public function setAvailableFrom(\DateTimeInterface $availableFrom): static
    {
        $this->availableFrom = $availableFrom;

        return $this;
    }

    public function getAvailableTo(): ?\DateTimeInterface
    {
        return $this->availableTo;
    }

    public function setAvailableTo(?\DateTimeInterface $availableTo): static
    {
        $this->availableTo = $availableTo;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getPoints(): float
    {
        return $this->points;
    }

    public function setPoints(float $points): static
    {
        $this->points = $points;

        return $this;
    }

    public function getEstimatedDuration(): ?int
    {
        return $this->estimatedDuration;
    }

    public function setEstimatedDuration(?int $estimatedDuration): static
    {
        $this->estimatedDuration = $estimatedDuration;

        return $this;
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

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getFeaturedImage(): ?string
    {
        return $this->featuredImage;
    }

    public function setFeaturedImage(?string $featuredImage): static
    {
        $this->featuredImage = $featuredImage;

        return $this;
    }

    /**
     * @return Collection<int, QuestStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(QuestStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setQuest($this);
        }

        return $this;
    }

    public function removeStep(QuestStep $step): static
    {
        if ($this->steps->removeElement($step)) {
            // set the owning side to null (unless already changed)
            if ($step->getQuest() === $this) {
                $step->setQuest(null);
            }
        }

        return $this;
    }

    /** @return string[] */
    public function getRequiredCapabilities(): array
    {
        return $this->requiredCapabilities;
    }

    /** @param string[] $capabilities */
    public function setRequiredCapabilities(array $capabilities): static
    {
        $this->requiredCapabilities = array_values(array_unique($capabilities));

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRecurrence(): ?array
    {
        return $this->recurrence;
    }

    /**
     * @param array<string, mixed>|null $recurrence
     */
    public function setRecurrence(?array $recurrence): static
    {
        // An empty array is not a policy — normalise it to null so "cleared in
        // the admin form" and "never set" mean the same thing downstream.
        $this->recurrence = ($recurrence === null || $recurrence === []) ? null : $recurrence;

        return $this;
    }

    /** Typed view of {@see getRecurrence()}; null when unset or malformed. */
    public function getRecurrencePolicy(): ?RecurrencePolicy
    {
        return RecurrencePolicy::fromArray($this->recurrence);
    }

    public function getAudience(): string
    {
        return $this->audience;
    }

    public function setAudience(?string $audience): static
    {
        // Anything unrecognised becomes `public`, not an exception. An audience is a
        // visibility hint and a malformed one must degrade to the safe, existing
        // behaviour rather than take the quest catalogue down — the same rule
        // `RecurrencePolicy::fromArray()` already follows for a bad policy.
        //
        // Safe in the direction that matters: an unknown value shows a quest that
        // might have been hidden, never hides one that should be shown, and the
        // start-time check is a separate gate.
        $this->audience = $audience === self::AUDIENCE_OPERATOR
            ? self::AUDIENCE_OPERATOR
            : self::AUDIENCE_PUBLIC;

        return $this;
    }

    public function isOperatorOnly(): bool
    {
        return $this->audience === self::AUDIENCE_OPERATOR;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRoutePolicy(): ?array
    {
        return $this->routePolicy;
    }

    /**
     * @param array<string, mixed>|null $routePolicy
     */
    public function setRoutePolicy(?array $routePolicy): static
    {
        // Empty normalises to null for the same reason recurrence does: "cleared in
        // the admin form" and "never set" must mean one thing downstream.
        $this->routePolicy = ($routePolicy === null || $routePolicy === []) ? null : $routePolicy;

        return $this;
    }

    /**
     * One route from the pool, or null when this quest does not vary.
     *
     * Returns a list of target KEYS, in the order this enrollment should walk them.
     * The caller stores it on the enrollment; nothing here mutates.
     *
     * Degrades to null rather than throwing on a malformed policy, on the same rule
     * as {@see setAudience()}: a bad policy costs the variation, never the quest.
     *
     * @return list<string>|null
     */
    public function drawRoute(?\Random\Randomizer $randomizer = null): ?array
    {
        $policy = $this->routePolicy;
        if ($policy === null || ($policy['mode'] ?? 'fixed') !== 'pool') {
            return null;
        }

        $routes = $policy['routes'] ?? null;
        if (!is_array($routes) || $routes === []) {
            return null;
        }

        $routes = array_values(array_filter($routes, static fn ($r): bool => is_array($r) && $r !== []));
        if ($routes === []) {
            return null;
        }

        $randomizer ??= new \Random\Randomizer();
        $chosen = $routes[$randomizer->getInt(0, count($routes) - 1)];

        return array_values(array_map(static fn ($k): string => (string) $k, $chosen));
    }

    /**
     * @return Collection<int, Device>
     */
    public function getArmedDevices(): Collection
    {
        return $this->armedDevices;
    }

    public function addArmedDevice(Device $device): static
    {
        if (!$this->armedDevices->contains($device)) {
            $this->armedDevices->add($device);
        }

        return $this;
    }

    public function removeArmedDevice(Device $device): static
    {
        $this->armedDevices->removeElement($device);

        return $this;
    }

    /** True when this quest is offered at $device (empty arming = everywhere). */
    public function isArmedAt(Device $device): bool
    {
        return $this->armedDevices->isEmpty() || $this->armedDevices->contains($device);
    }

    /** @param string[] $deviceCapabilities */
    public function isSupportedBy(array $deviceCapabilities): bool
    {
        return [] === array_diff($this->requiredCapabilities, $deviceCapabilities);
    }
}
