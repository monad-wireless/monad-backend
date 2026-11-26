<?php

namespace App\Entity;

use App\Repository\DeviceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A physical node in the CSI fleet — the thing a QR sticker is stuck to (IP-128).
 *
 * The `slug` is the fleet identity (`monad01`), and it is the same string in five
 * places by design: the system hostname, the Headscale/MagicDNS name, the S3
 * prefix, the printed label, and the `/d/<slug>` URL that label's QR encodes.
 * One name everywhere is what lets a person read a sticker, type it, and land on
 * the right record.
 *
 * WHAT THIS DELIBERATELY DOES NOT HOLD
 *
 * - **Telemetry** (temperature, CPU, capture rate). Those have a 30-second
 *   lifecycle and an owner already — Mimir. A column here would be a second,
 *   permanently-stale source of truth for a number the portal reads live.
 * - **A BLE MAC.** Verified 2026-08-13: the nodes run `bluetoothd` but register
 *   no HCI adapter, so there is no address to store. A nullable column would
 *   invite a quest step to target a radio that does not exist.
 * - **A role** (illuminator / sensor). It moves between hosts between
 *   experiments and lives in the Ansible inventory; persisting it here would
 *   date every row the next time an arm is re-planned.
 *
 * `radioMac` IS held, but is operator-only and never serialised to a public
 * response — see `DevicePublicResponseDto`. The sticker discloses it to whoever
 * is standing next to the box, which is a physically-scoped disclosure; a public
 * slug-to-MAC page would make the same fact global, permanent and indexable.
 */
#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: '`devices`')]
#[ORM\Index(name: 'device_active_idx', columns: ['is_active'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'A device with this slug already exists')]
class Device
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    /**
     * The fleet identity. Lowercase because it travels in a URL that three
     * case-sensitive layers must agree on (Apple's AASA matching defaults to
     * case-sensitive, Android path matchers are literal, and FastAPI routing is
     * case-sensitive), so mixed case would work on some phones and not others.
     */
    #[ORM\Column(length: 32, unique: true)]
    #[Assert\NotBlank(message: 'Slug is required')]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Slug must be lowercase letters, digits or hyphens')]
    #[Assert\Length(max: 32)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Label is required')]
    #[Assert\Length(max: 255)]
    private ?string $label = null;

    /** Where it hangs, in human words. The surveyed position is `lab_placements` (IP-157), not this. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $location = null;

    /** Reuses the vocabulary already live in step configs, e.g. `fiit/library`. */
    #[ORM\Column(name: 'site_ref', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $siteRef = null;

    /** The per-node paragraph shown on the public `/d/<slug>` page. */
    #[ORM\Column(name: 'public_blurb', type: 'text', nullable: true)]
    private ?string $publicBlurb = null;

    /** AX210 MAC. Operator-only: never serialised into a public response. */
    #[ORM\Column(name: 'radio_mac', length: 17, nullable: true)]
    #[Assert\Length(max: 17)]
    private ?string $radioMac = null;

    /**
     * Whether the node is in service. Decommissioning sets this false rather
     * than deleting the row: enrollments point at the device as measurement
     * provenance, and deleting it would silently rewrite what a past run means.
     */
    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'commissioned_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $commissionedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getSiteRef(): ?string
    {
        return $this->siteRef;
    }

    public function setSiteRef(?string $siteRef): static
    {
        $this->siteRef = $siteRef;

        return $this;
    }

    public function getPublicBlurb(): ?string
    {
        return $this->publicBlurb;
    }

    public function setPublicBlurb(?string $publicBlurb): static
    {
        $this->publicBlurb = $publicBlurb;

        return $this;
    }

    public function getRadioMac(): ?string
    {
        return $this->radioMac;
    }

    public function setRadioMac(?string $radioMac): static
    {
        $this->radioMac = $radioMac;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCommissionedAt(): ?\DateTimeImmutable
    {
        return $this->commissionedAt;
    }

    public function setCommissionedAt(?\DateTimeImmutable $commissionedAt): static
    {
        $this->commissionedAt = $commissionedAt;

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

    public function __toString(): string
    {
        return $this->slug ?? '(new device)';
    }
}
