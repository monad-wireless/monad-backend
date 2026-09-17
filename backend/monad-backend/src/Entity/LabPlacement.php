<?php

namespace App\Entity;

use App\Repository\LabPlacementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One mirrored placement: a marker card or a fleet node on one floor (IP-157).
 *
 * A MIRROR, never the position of record. Positions live in PostGIS layers
 * (`fiit-ground-markers`, `fiit-ground-fleet`) in `monad_gis`, a database this API's role is
 * refused access to on purpose. `lab_placements_write` replaces every row of one floor in one
 * transaction from a payload `monad-knowledge lab placements-export` prints, and stamps
 * `synced_at` and `sync_id` so the board can show how old the mirror is and which sync a row
 * came from. A stale mirror is displayed, not inferred.
 *
 * The marker rule stands: a marker EXISTS because a quest step names it (MarkerService). This row
 * says where the card is, and the drift verdict (matched | spare | mismatch) is the comparison
 * of the two lists.
 */
#[ORM\Entity(repositoryClass: LabPlacementRepository::class)]
#[ORM\Table(name: 'lab_placements')]
#[ORM\UniqueConstraint(name: 'lab_placements_floor_key_key', columns: ['floor', 'key'])]
class LabPlacement
{
    public const KIND_CARD = 'card';
    public const KIND_NODE = 'node';
    public const KINDS = [self::KIND_CARD, self::KIND_NODE];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** `MONAD-FP-07` for a card, `monad03` for a node: the key a probe target's value resolves to. */
    #[ORM\Column(name: '`key`', type: Types::TEXT)]
    private string $key;

    /** card | node. Closed, for the same reason a probe target's kind is. */
    #[ORM\Column(length: 8)]
    private string $kind;

    /** The floor bundle, e.g. `fiit-ground-0`. */
    #[ORM\Column(type: Types::TEXT)]
    private string $floor;

    /** The PostGIS layer the row came from, e.g. `fiit-ground-markers`. */
    #[ORM\Column(type: Types::TEXT)]
    private string $layer;

    /** Cell name in PostGIS; NULL when the point is not assigned to a room there. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $room = null;

    #[ORM\Column(name: 'x_m', type: Types::FLOAT)]
    private float $xM;

    #[ORM\Column(name: 'y_m', type: Types::FLOAT)]
    private float $yM;

    #[ORM\Column(name: 'z_cm', type: Types::FLOAT, nullable: true)]
    private ?float $zCm = null;

    /** lidar | surveyed | proposed, as PostGIS records it. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $provenance = null;

    /** When the source row last changed in PostGIS, when the export knows. */
    #[ORM\Column(name: 'source_updated_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sourceUpdatedAt = null;

    #[ORM\Column(name: 'synced_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    /** One id per `lab_placements_write` call, so a half-applied sync is visible as two ids on one floor. */
    #[ORM\Column(name: 'sync_id', type: 'uuid')]
    private Uuid $syncId;

    public function __construct(
        string $key,
        string $kind,
        string $floor,
        string $layer,
        float $xM,
        float $yM,
        Uuid $syncId,
        ?\DateTimeImmutable $syncedAt = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Placement kind must be card or node, "%s" given.', $kind));
        }
        $this->id = Uuid::v4();
        $this->key = $key;
        $this->kind = $kind;
        $this->floor = $floor;
        $this->layer = $layer;
        $this->xM = $xM;
        $this->yM = $yM;
        $this->syncId = $syncId;
        $this->syncedAt = $syncedAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getFloor(): string
    {
        return $this->floor;
    }

    public function getLayer(): string
    {
        return $this->layer;
    }

    public function getRoom(): ?string
    {
        return $this->room;
    }

    public function setRoom(?string $room): static
    {
        $this->room = $room;

        return $this;
    }

    public function getXM(): float
    {
        return $this->xM;
    }

    public function getYM(): float
    {
        return $this->yM;
    }

    public function getZCm(): ?float
    {
        return $this->zCm;
    }

    public function setZCm(?float $zCm): static
    {
        $this->zCm = $zCm;

        return $this;
    }

    public function getProvenance(): ?string
    {
        return $this->provenance;
    }

    public function setProvenance(?string $provenance): static
    {
        $this->provenance = $provenance;

        return $this;
    }

    public function getSourceUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->sourceUpdatedAt;
    }

    public function setSourceUpdatedAt(?\DateTimeImmutable $sourceUpdatedAt): static
    {
        $this->sourceUpdatedAt = $sourceUpdatedAt;

        return $this;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function getSyncId(): Uuid
    {
        return $this->syncId;
    }
}
