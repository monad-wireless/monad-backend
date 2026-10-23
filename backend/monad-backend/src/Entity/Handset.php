<?php

namespace App\Entity;

use App\Repository\HandsetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One installation of the participant app on one phone (IP-149).
 *
 * NOT A `Device`. A `Device` is a fleet node — the box a sticker is stuck to. A
 * handset is the thing in a participant's hand, and it is the other half of every
 * measurement provenance: `quest_enrollments.device_id` says which radio listened,
 * `quest_enrollments.handset_id` says which transmitter walked.
 *
 * WHAT A HANDSET IS. An app installation, identified by a UUID the app generates
 * once and keeps in its own settings store. A reinstall is a new handset. That is
 * honest rather than sloppy: the app's local state, granted permissions and clock
 * epoch are new too. One physical phone can therefore appear as two rows, and the
 * inventory groups by `machine` to see the phone behind the installations. No
 * platform device identifier (`identifierForVendor`, `ANDROID_ID`) is read or
 * stored, by owner decision (IP-149 Q1).
 *
 * WHAT LIVES HERE AND WHAT DOES NOT. The columns are the facts that do not change
 * between runs — platform, machine identifier, manufacturer, model. Everything
 * that drifts (OS version, app build, capability tokens, sensor inventory, thermal
 * state) is frozen per run on `QuestEnrollment::$handsetSnapshot`; the copy kept
 * here in `$lastDescriptor` is a READ MODEL for the inventory table, refreshed on
 * every start, never the evidence.
 *
 * NO HARD DELETE. Every enrollment that names this row is a measurement whose
 * transmitter this describes; `ON DELETE RESTRICT` on the enrollment side.
 */
#[ORM\Entity(repositoryClass: HandsetRepository::class)]
#[ORM\Table(name: 'handsets')]
#[ORM\Index(name: 'handset_platform_machine_idx', columns: ['platform', 'machine'])]
#[ORM\Index(name: 'handset_last_seen_idx', columns: ['last_seen_at'])]
class Handset
{
    public const PLATFORMS = ['ios', 'android'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** The app's `handset_id`. Unique: one row per installation. */
    #[ORM\Column(name: 'installation_id', length: 64, unique: true)]
    private string $installationId;

    #[ORM\Column(length: 16)]
    private string $platform;

    /** `iPhone15,2`, `a54x` — the hardware identifier the platform publishes. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $machine = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $manufacturer = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $model = null;

    /**
     * The one operator-editable column: "operator iPhone 15 Pro", "loaner A".
     * Everything else on this row is reported by the phone and is not for editing.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    /**
     * The most recent full descriptor, as sent. A read model for the inventory
     * table (latest OS, latest build, current tokens) — the per-run evidence is
     * `QuestEnrollment::$handsetSnapshot`.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'last_descriptor', type: Types::JSON, options: ['jsonb' => true])]
    private array $lastDescriptor = [];

    #[ORM\Column(name: 'first_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    /** Denormalised for the inventory table; the detail page counts from the enrollments. */
    #[ORM\Column(name: 'enrollment_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $enrollmentCount = 0;

    public function __construct(string $installationId, string $platform, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->id = Uuid::v4();
        $this->installationId = $installationId;
        $this->platform = $platform;
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInstallationId(): string
    {
        return $this->installationId;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getMachine(): ?string
    {
        return $this->machine;
    }

    public function getManufacturer(): ?string
    {
        return $this->manufacturer;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = ($label === null || trim($label) === '') ? null : trim($label);

        return $this;
    }

    /** @return array<string, mixed> */
    public function getLastDescriptor(): array
    {
        return $this->lastDescriptor;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getEnrollmentCount(): int
    {
        return $this->enrollmentCount;
    }

    /**
     * Record one more start on this installation.
     *
     * The stable hardware columns are filled when the descriptor names them and
     * left alone when it does not: a build that stopped reporting `manufacturer`
     * must not blank a fact an earlier build established.
     *
     * @param array<string, mixed> $descriptor the validated descriptor, as sent
     */
    public function observe(array $descriptor, ?\DateTimeImmutable $now = null): static
    {
        $now ??= new \DateTimeImmutable();
        $this->machine = self::str($descriptor['machine'] ?? null, 64) ?? $this->machine;
        $this->manufacturer = self::str($descriptor['manufacturer'] ?? null, 64) ?? $this->manufacturer;
        $this->model = self::str($descriptor['model'] ?? null, 128) ?? $this->model;
        $this->lastDescriptor = $descriptor;
        $this->lastSeenAt = $now;
        ++$this->enrollmentCount;

        return $this;
    }

    /** Latest OS version as reported, for the inventory column. */
    public function getOsVersion(): ?string
    {
        return self::str($this->lastDescriptor['os_version'] ?? null, 64);
    }

    /** Latest build id (or app version when the build id is absent). */
    public function getBuild(): ?string
    {
        return self::str($this->lastDescriptor['build_id'] ?? null, 128)
            ?? self::str($this->lastDescriptor['app_version'] ?? null, 64);
    }

    /** @return list<string> */
    public function getCapabilities(): array
    {
        $tokens = $this->lastDescriptor['capabilities'] ?? [];
        if (!is_array($tokens)) {
            return [];
        }
        $out = array_values(array_filter($tokens, 'is_string'));
        sort($out);

        return $out;
    }

    /** A one-line human name: label when set, else manufacturer + model + machine. */
    public function displayName(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }
        $parts = array_filter([$this->manufacturer, $this->model, $this->machine !== null ? "({$this->machine})" : null]);

        return $parts === [] ? $this->installationId : implode(' ', $parts);
    }

    public function __toString(): string
    {
        return $this->displayName();
    }

    private static function str(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
