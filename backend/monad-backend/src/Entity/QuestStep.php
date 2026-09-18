<?php

namespace App\Entity;

use App\Enum\QuestStepType;
use App\Repository\QuestStepRepository;
use App\Validator\Constraints\ValidStepConfig;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: QuestStepRepository::class)]
#[ORM\Table(name: 'quest_steps')]
#[ORM\UniqueConstraint(name: 'quest_step_order_idx', columns: ['quest_id', '`order`'])]
#[ORM\HasLifecycleCallbacks]
class QuestStep
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Quest::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Quest is required')]
    private ?Quest $quest = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Step name is required')]
    #[Assert\Length(max: 255, maxMessage: 'Step name cannot be longer than {{ limit }} characters')]
    private ?string $name = null;

    #[ORM\Column(type: 'string', enumType: QuestStepType::class)]
    #[Assert\NotNull(message: 'Step type is required')]
    private ?QuestStepType $type = null;

    #[ORM\Column(name: '`order`', type: Types::INTEGER)]
    #[Assert\NotNull(message: 'Step order is required')]
    #[Assert\PositiveOrZero(message: 'Step order must be a positive number or zero')]
    private ?int $order = null;

    /**
     * Validated against the step type's schema (App\Quest\Schema) on the ENTITY, so the admin
     * form, `lab_quest_write` and the API all refuse the same malformed config (IP-157). Before
     * this the constraint sat only on the API DTO, and an `observe` step without `min_readings`
     * could be saved from /admin and surface on a phone as a step that does nothing.
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotNull(message: 'Step configuration is required')]
    #[ValidStepConfig]
    private array $config = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getQuest(): ?Quest
    {
        return $this->quest;
    }

    public function setQuest(?Quest $quest): static
    {
        $this->quest = $quest;

        return $this;
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

    public function getType(): ?QuestStepType
    {
        return $this->type;
    }

    public function setType(QuestStepType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getOrder(): ?int
    {
        return $this->order;
    }

    public function setOrder(int $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): static
    {
        $this->config = $config;

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
}
