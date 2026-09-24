<?php

namespace App\Entity;

use App\Enum\QuestEnrollmentStatus;
use App\Repository\QuestEnrollmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: QuestEnrollmentRepository::class)]
#[ORM\Table(name: 'quest_enrollments')]
#[ORM\Index(name: 'enrollment_status_idx', columns: ['status'])]
#[ORM\Index(name: 'user_quest_idx', columns: ['user_id', 'quest_id'])]
#[ORM\Index(name: 'enrollment_user_awarded_idx', columns: ['user_id', 'awarded_at'])]
#[ORM\Index(name: 'enrollment_handset_idx', columns: ['handset_id'])]
#[ORM\HasLifecycleCallbacks]
class QuestEnrollment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    #[Assert\NotNull(message: 'User is required')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Quest::class)]
    #[ORM\JoinColumn(name: 'quest_id', nullable: false)]
    #[Assert\NotNull(message: 'Quest is required')]
    private ?Quest $quest = null;

    #[ORM\Column(type: 'string', enumType: QuestEnrollmentStatus::class)]
    #[Assert\NotNull(message: 'Status is required')]
    private QuestEnrollmentStatus $status = QuestEnrollmentStatus::IN_PROGRESS;

    #[ORM\Column(name: 'data_path', type: 'string', length: 512, nullable: true)]
    #[Assert\Length(max: 512, maxMessage: 'Data path cannot be longer than {{ limit }} characters')]
    private ?string $dataPath = null;

    /**
     * Which physical node produced this run (IP-128).
     *
     * MEASUREMENT PROVENANCE once written, not bookkeeping — it is the record of
     * where a measurement came from, so the admin renders it read-only and the
     * device row can be deactivated but never deleted (ON DELETE RESTRICT).
     */
    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'device_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Device $device = null;

    /**
     * When the SERVER received the completion (IP-128).
     *
     * Distinct from `$completedAt`, which `QuestController::completeQuest()` sets
     * from the request body. A cooldown measured against a client-supplied
     * timestamp is not a cooldown — a backdated finish clears it instantly — so
     * the recurrence gate reads only this column.
     */
    #[ORM\Column(name: 'completion_received_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completionReceivedAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime', nullable: true)]
    private ?\DateTime $completedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * The target keys this enrollment was actually asked to walk, in order (IP-145).
     *
     * NULL means the quest's declared step order was served, which is every
     * enrollment before IP-145 and every enrollment on a `fixed` quest.
     *
     * The SEQUENCE is stored rather than the random seed that produced it. A seed
     * only reproduces an order against a frozen generator, and the generator here is
     * not frozen: `loop_order` gained door awareness on 2026-09-01 and will change
     * again. A few hundred bytes buys an answer to "what was this walker actually
     * told to do" that survives any future change to the ordering code.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'realised_steps', type: Types::JSON, nullable: true)]
    private ?array $realisedSteps = null;

    /**
     * What this completion was worth, frozen when it completed (IP-145).
     *
     * Frozen rather than derived from `quests.points`, because that column is
     * mutable and a derived total silently rewrites history the first time a quest
     * is re-valued. Before IP-145 there was no ledger at all: the completion
     * response returned the quest's current value and stored nothing.
     */
    #[ORM\Column(name: 'points_awarded', type: Types::FLOAT, nullable: true)]
    private ?float $pointsAwarded = null;

    /**
     * When the award was frozen.
     *
     * Equal to `completed_at` to the second means the row was BACKFILLED by
     * Version20260901150000 rather than stamped at the time. Nothing recorded what
     * those walkers were promised, so the backfill is a reconstruction and any
     * pre/post analysis must exclude those rows.
     */
    #[ORM\Column(name: 'awarded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $awardedAt = null;

    /**
     * Which app installation walked this run (IP-149).
     *
     * The other half of the provenance `$device` records: the device is the radio
     * that listened, the handset is the transmitter that walked. Same posture —
     * written once at start, never updated, rendered read-only, RESTRICT on delete.
     * NULL means the app build sent no descriptor (pre-IP-149), which stays valid.
     */
    #[ORM\ManyToOne(targetEntity: Handset::class)]
    #[ORM\JoinColumn(name: 'handset_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Handset $handset = null;

    /**
     * The handset descriptor AS SENT at start, verbatim (IP-149).
     *
     * OS version, app build, capability tokens, sensor inventory and thermal state
     * all drift between runs, so the row on `handsets` cannot say what this phone
     * was at this moment; this column can. Not normalised, for the reason
     * `$realisedSteps` stores a sequence rather than a seed: the analysis asks
     * "what did this phone report then", and a normalisation step is a place
     * where two builds could be made to look alike.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'handset_snapshot', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $handsetSnapshot = null;

    #[ORM\OneToMany(targetEntity: QuestStepCompletion::class, mappedBy: 'enrollment', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $stepCompletions;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->status = QuestEnrollmentStatus::IN_PROGRESS;
        $this->stepCompletions = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
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

    public function getStatus(): QuestEnrollmentStatus
    {
        return $this->status;
    }

    public function setStatus(QuestEnrollmentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDataPath(): ?string
    {
        return $this->dataPath;
    }

    public function setDataPath(?string $dataPath): static
    {
        $this->dataPath = $dataPath;

        return $this;
    }

    public function getCompletedAt(): ?\DateTime
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTime $completedAt): static
    {
        $this->completedAt = $completedAt;

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

    /**
     * @return Collection<int, QuestStepCompletion>
     */
    public function getStepCompletions(): Collection
    {
        return $this->stepCompletions;
    }

    public function addStepCompletion(QuestStepCompletion $stepCompletion): static
    {
        if (!$this->stepCompletions->contains($stepCompletion)) {
            $this->stepCompletions->add($stepCompletion);
            $stepCompletion->setEnrollment($this);
        }

        return $this;
    }

    public function removeStepCompletion(QuestStepCompletion $stepCompletion): static
    {
        if ($this->stepCompletions->removeElement($stepCompletion)) {
            // set the owning side to null (unless already changed)
            if ($stepCompletion->getEnrollment() === $this) {
                $stepCompletion->setEnrollment(null);
            }
        }

        return $this;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): static
    {
        $this->device = $device;

        return $this;
    }

    public function getCompletionReceivedAt(): ?\DateTimeImmutable
    {
        return $this->completionReceivedAt;
    }

    public function getHandset(): ?Handset
    {
        return $this->handset;
    }

    /** @return array<string, mixed>|null */
    public function getHandsetSnapshot(): ?array
    {
        return $this->handsetSnapshot;
    }

    /**
     * Freeze which installation walked this run and what it reported. One-way.
     *
     * A second call is ignored rather than overwriting, for the reason
     * `awardPoints()` is: provenance written at start must not be movable later.
     *
     * @param array<string, mixed> $snapshot the validated descriptor, as sent
     */
    public function attachHandset(Handset $handset, array $snapshot): static
    {
        if ($this->handset !== null) {
            return $this;
        }
        $this->handset = $handset;
        $this->handsetSnapshot = $snapshot;

        return $this;
    }

    /** Stamped by the server on receipt; never taken from a request body. */
    public function markCompletionReceived(?\DateTimeImmutable $at = null): static
    {
        $this->completionReceivedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getRealisedSteps(): ?array
    {
        return $this->realisedSteps;
    }

    /**
     * @param list<string>|null $realisedSteps
     */
    public function setRealisedSteps(?array $realisedSteps): static
    {
        $this->realisedSteps = ($realisedSteps === null || $realisedSteps === []) ? null : $realisedSteps;

        return $this;
    }

    public function getPointsAwarded(): ?float
    {
        return $this->pointsAwarded;
    }

    public function getAwardedAt(): ?\DateTimeImmutable
    {
        return $this->awardedAt;
    }

    /**
     * Freeze what this completion was worth. Idempotent, and deliberately one-way.
     *
     * A second call is ignored rather than overwriting. Re-completing, a replayed
     * request, or a later re-valuation of the quest must not change what a finished
     * walk was worth: that is the entire reason the value is stored here instead of
     * being read from `quests.points` when someone asks for a total.
     */
    public function awardPoints(float $points, ?\DateTimeImmutable $at = null): static
    {
        if ($this->pointsAwarded !== null) {
            return $this;
        }

        $this->pointsAwarded = $points;
        $this->awardedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

}
