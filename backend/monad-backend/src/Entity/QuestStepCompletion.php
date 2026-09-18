<?php

namespace App\Entity;

use App\Enum\QuestStepCompletionStatus;
use App\Repository\QuestStepCompletionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: QuestStepCompletionRepository::class)]
#[ORM\Table(name: 'quest_step_completions')]
#[ORM\UniqueConstraint(name: 'enrollment_step_idx', columns: ['enrollment_id', 'step_id'])]
#[ORM\HasLifecycleCallbacks]
class QuestStepCompletion
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: QuestEnrollment::class, inversedBy: 'stepCompletions')]
    #[ORM\JoinColumn(name: 'enrollment_id', nullable: false)]
    #[Assert\NotNull(message: 'Enrollment is required')]
    private ?QuestEnrollment $enrollment = null;

    #[ORM\ManyToOne(targetEntity: QuestStep::class)]
    #[ORM\JoinColumn(name: 'step_id', nullable: false)]
    #[Assert\NotNull(message: 'Step is required')]
    private ?QuestStep $step = null;

    #[ORM\Column(type: 'string', enumType: QuestStepCompletionStatus::class)]
    #[Assert\NotNull(message: 'Status is required')]
    private QuestStepCompletionStatus $status = QuestStepCompletionStatus::IN_PROGRESS;

    #[ORM\Column(name: 'started_at', type: 'datetime', nullable: true)]
    private ?\DateTime $startedAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime', nullable: true)]
    private ?\DateTime $completedAt = null;

    /**
     * Monotonic clock reading at this step, in nanoseconds (IP-128).
     *
     * WHY WALL CLOCK IS NOT ENOUGH. These labels only have research value if they
     * can be time-joined to the CSI the node recorded, and neither clock in that
     * join is trustworthy on its own: the fleet nodes have no RTC battery, so
     * every node boots hours-to-weeks in the past and chrony steps it (a +51 s
     * step has been observed mid-session), while a handset's wall clock can be
     * adjusted by the user or the network at any moment. A step whose only
     * timestamp is wall-clock therefore cannot be placed against a capture with
     * any confidence.
     *
     * A monotonic reading cannot be steered and never goes backwards, so the pair
     * (`mono_ns`, `completed_at`) makes drift over a run *measurable* instead of
     * assumed — which is the same contract the ground-truth channel already uses
     * (`GroundTruthScan::$monoNs`), deliberately spelled the same way so one
     * analysis join covers both.
     *
     * Nullable: pre-IP-128 rows and clients that do not send it stay valid, and
     * `bigint` maps to a PHP string because nanoseconds overflow 32-bit ints.
     */
    #[ORM\Column(name: 'mono_ns', type: 'bigint', nullable: true)]
    private ?string $monoNs = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'step_data', type: 'json')]
    private array $stepData = [];

    #[ORM\OneToMany(targetEntity: QuestStepSkipRecord::class, mappedBy: 'stepCompletion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $skipRecords;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->status = QuestStepCompletionStatus::IN_PROGRESS;
        $this->stepData = [];
        $this->skipRecords = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEnrollment(): ?QuestEnrollment
    {
        return $this->enrollment;
    }

    public function setEnrollment(?QuestEnrollment $enrollment): static
    {
        $this->enrollment = $enrollment;

        return $this;
    }

    public function getStep(): ?QuestStep
    {
        return $this->step;
    }

    public function setStep(?QuestStep $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function getStatus(): QuestStepCompletionStatus
    {
        return $this->status;
    }

    public function setStatus(QuestStepCompletionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStartedAt(): ?\DateTime
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTime $startedAt): static
    {
        $this->startedAt = $startedAt;

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

    public function getStepData(): array
    {
        return $this->stepData;
    }

    public function setStepData(array $stepData): static
    {
        $this->stepData = $stepData;

        return $this;
    }

    /**
     * @return Collection<int, QuestStepSkipRecord>
     */
    public function getSkipRecords(): Collection
    {
        return $this->skipRecords;
    }

    public function addSkipRecord(QuestStepSkipRecord $skipRecord): static
    {
        if (!$this->skipRecords->contains($skipRecord)) {
            $this->skipRecords->add($skipRecord);
            $skipRecord->setStepCompletion($this);
        }

        return $this;
    }

    public function removeSkipRecord(QuestStepSkipRecord $skipRecord): static
    {
        if ($this->skipRecords->removeElement($skipRecord)) {
            // set the owning side to null (unless already changed)
            if ($skipRecord->getStepCompletion() === $this) {
                $skipRecord->setStepCompletion(null);
            }
        }

        return $this;
    }

    public function getMonoNs(): ?string
    {
        return $this->monoNs;
    }

    public function setMonoNs(?string $monoNs): static
    {
        $this->monoNs = $monoNs;

        return $this;
    }
}
