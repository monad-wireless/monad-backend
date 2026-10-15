<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One published step set of a quest (2026-10-15).
 *
 * A quest is ONE row per name and is edited in place; this table is its history. Every write
 * that changes what a participant is asked to do appends a row here and bumps
 * {@see Quest::$revision}, and every enrollment records the revision it was served. So "which
 * Counting did this run use" is a join, and "what did revision 3 ask" is answerable even if
 * nobody ran revision 3.
 *
 * `steps` uses the per-step shape of {@see QuestStepCompletion::snapshotStep()}:
 * `{order, name, type, config}`, in declared order. Rows are written once and never updated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quest_revisions')]
#[ORM\UniqueConstraint(name: 'quest_revision_number', columns: ['quest_id', 'revision'])]
class QuestRevision
{
    /** The write paths that may publish a revision. */
    public const SOURCE_MCP = 'mcp';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_MIGRATION = 'migration';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Quest::class)]
    #[ORM\JoinColumn(name: 'quest_id', nullable: false, onDelete: 'CASCADE')]
    private Quest $quest;

    #[ORM\Column(type: Types::INTEGER)]
    private int $revision;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $steps;

    /** @var list<string> */
    #[ORM\Column(name: 'required_capabilities', type: Types::JSON, options: ['jsonb' => true])]
    private array $requiredCapabilities;

    #[ORM\Column(length: 16)]
    private string $source;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array<string, mixed>> $steps
     * @param list<string>               $requiredCapabilities
     */
    public function __construct(Quest $quest, int $revision, array $steps, array $requiredCapabilities, string $source, ?User $author)
    {
        $this->id = Uuid::v4();
        $this->quest = $quest;
        $this->revision = $revision;
        $this->steps = $steps;
        $this->requiredCapabilities = $requiredCapabilities;
        $this->source = $source;
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getQuest(): Quest
    {
        return $this->quest;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    /** @return list<array<string, mixed>> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** @return list<string> */
    public function getRequiredCapabilities(): array
    {
        return $this->requiredCapabilities;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
