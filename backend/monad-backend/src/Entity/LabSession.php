<?php

namespace App\Entity;

use App\Repository\LabSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One recording session the app uploaded — the register behind `datasets/monad-app-sessions/` (IP-149).
 *
 * NOT A GROUND-TRUTH SESSION. `GroundTruthScan::$labSessionId` is the event the
 * phones were told to stamp on scans, and it comes into existence when the first
 * scan arrives. THIS is one phone's uploaded artefacts, keyed by the session id
 * the app minted (`X-Session-Id`). A scan can name a recording session through
 * `GroundTruthScan::$recordingSessionId`, and that is the only join between them.
 *
 * WRITTEN BY THE UPLOAD PATH, NEVER BY A FORM. `S3Controller` upserts a row on
 * every accepted artefact and completes it when `metadata.json` — the sidecar,
 * uploaded last by client contract — arrives. Until 2026-09-04 the sidecar was
 * parsed for four telemetry histograms and then discarded, and the S3 prefix was
 * the only register: a session that failed to complete was indistinguishable from
 * one nobody ran. The rows are written through native upserts in
 * {@see LabSessionRepository}, not through this entity, because ten handsets
 * flush concurrently and two artefacts of one session may land in the same
 * millisecond; this class is the READ side.
 *
 * `$sidecar` is the whole `metadata.json`, as uploaded, up to the 1 MB
 * inspection cap the controller already applied. The typed columns beside it
 * are projections for filtering and for the join to the enrollment; the sidecar
 * is the evidence.
 */
#[ORM\Entity(repositoryClass: LabSessionRepository::class, readOnly: true)]
#[ORM\Table(name: 'lab_sessions')]
#[ORM\Index(name: 'lab_session_started_idx', columns: ['started_wall_ms'])]
#[ORM\Index(name: 'lab_session_participant_idx', columns: ['participant_id'])]
#[ORM\Index(name: 'lab_session_enrollment_idx', columns: ['enrollment_id'])]
#[ORM\Index(name: 'lab_session_completed_idx', columns: ['completed_at'])]
class LabSession
{
    /** The app's session id (`X-Session-Id`), after the same sanitiser the S3 key uses. */
    #[ORM\Id]
    #[ORM\Column(length: 128)]
    private string $id;

    /** Pseudonymous participant key — the first segment of the S3 prefix. */
    #[ORM\Column(name: 'participant_id', length: 128)]
    private string $participantId;

    /** The authenticated uploader, when known. Nullable: a backfilled row may have none. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** From the sidecar's `identity.enrollment_id`, resolved only when it names a row. */
    #[ORM\ManyToOne(targetEntity: QuestEnrollment::class)]
    #[ORM\JoinColumn(name: 'enrollment_id', nullable: true, onDelete: 'SET NULL')]
    private ?QuestEnrollment $enrollment = null;

    #[ORM\ManyToOne(targetEntity: Quest::class)]
    #[ORM\JoinColumn(name: 'quest_id', nullable: true, onDelete: 'SET NULL')]
    private ?Quest $quest = null;

    /** From `environment.handset.handset_id` → `handsets.installation_id`. */
    #[ORM\ManyToOne(targetEntity: Handset::class)]
    #[ORM\JoinColumn(name: 'handset_id', nullable: true, onDelete: 'SET NULL')]
    private ?Handset $handset = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $site = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $roles = [];

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $platform = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $machine = null;

    #[ORM\Column(name: 'build_id', length: 128, nullable: true)]
    private ?string $buildId = null;

    #[ORM\Column(name: 'started_wall_ms', type: Types::BIGINT, nullable: true)]
    private ?string $startedWallMs = null;

    #[ORM\Column(name: 'ended_wall_ms', type: Types::BIGINT, nullable: true)]
    private ?string $endedWallMs = null;

    /** Non-null when the session did not end through `stop()`; the data is still uploaded. */
    #[ORM\Column(name: 'interrupted_reason', type: Types::TEXT, nullable: true)]
    private ?string $interruptedReason = null;

    /** @var array<string, mixed>|null the whole `metadata.json` */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $sidecar = null;

    /**
     * `{filename: {bytes, content_type, stored_at, transport}}`.
     * `transport` is `single`, `multipart`, or `listed` for a backfilled row built from S3 alone.
     *
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $artefacts = [];

    #[ORM\Column(name: 'first_artefact_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $firstArtefactAt;

    /** When `metadata.json` arrived. Null means incomplete: streams landed, no sidecar. */
    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): string
    {
        return $this->id;
    }

    public function getParticipantId(): string
    {
        return $this->participantId;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getEnrollment(): ?QuestEnrollment
    {
        return $this->enrollment;
    }

    public function getQuest(): ?Quest
    {
        return $this->quest;
    }

    public function getHandset(): ?Handset
    {
        return $this->handset;
    }

    public function getSite(): ?string
    {
        return $this->site;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function getMachine(): ?string
    {
        return $this->machine;
    }

    public function getBuildId(): ?string
    {
        return $this->buildId;
    }

    public function getStartedWallMs(): ?int
    {
        return $this->startedWallMs === null ? null : (int) $this->startedWallMs;
    }

    public function getEndedWallMs(): ?int
    {
        return $this->endedWallMs === null ? null : (int) $this->endedWallMs;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return self::instant($this->getStartedWallMs());
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return self::instant($this->getEndedWallMs());
    }

    /** Seconds, when both ends are known. */
    public function getDurationSeconds(): ?int
    {
        $start = $this->getStartedWallMs();
        $end = $this->getEndedWallMs();
        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        return intdiv($end - $start, 1000);
    }

    public function getInterruptedReason(): ?string
    {
        return $this->interruptedReason;
    }

    /** @return array<string, mixed>|null */
    public function getSidecar(): ?array
    {
        return $this->sidecar;
    }

    /** @return array<string, array<string, mixed>> */
    public function getArtefacts(): array
    {
        return $this->artefacts;
    }

    public function getArtefactCount(): int
    {
        return count($this->artefacts);
    }

    public function getArtefactBytes(): int
    {
        $total = 0;
        foreach ($this->artefacts as $entry) {
            $total += (int) ($entry['bytes'] ?? 0);
        }

        return $total;
    }

    public function hasArtefact(string $filename): bool
    {
        return array_key_exists($filename, $this->artefacts);
    }

    public function getFirstArtefactAt(): \DateTimeImmutable
    {
        return $this->firstArtefactAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isComplete(): bool
    {
        return $this->completedAt !== null;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** The first eight characters — how the public site names a walk, and how the admin lists one. */
    public function shortId(): string
    {
        return substr($this->id, 0, 8);
    }

    public function __toString(): string
    {
        return $this->id;
    }

    private static function instant(?int $wallMs): ?\DateTimeImmutable
    {
        if ($wallMs === null || $wallMs <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . intdiv($wallMs, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }
}
