<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Device;
use App\Entity\Quest;
use App\Enum\RecurrenceScope;
use App\Quest\QuestSpecMapper;
use App\Quest\RecurrencePolicy;
use App\Quest\Schema\StartSchema;

/**
 * The quest builder's header form, as a flat object the Symfony Form binds to (IP-157).
 *
 * Not the entity: three fields are split or reshaped on the way to the form. `recurrence` is
 * one JSON column and two fields here (scope plus cooldown, with "unlimited" as the null
 * scope); `route_policy` is a mode plus a textarea of routes; the four session features live on
 * the start STEP's config and are edited beside the header because they are session-scoped.
 * `fromQuest()` and `applyTo()` are the two directions, and the recurrence mapping is pinned
 * by `tests/Form/QuestHeaderDataTest.php`.
 */
final class QuestHeaderData
{
    /** The closed capability token list the app sends (DeviceCapabilities). */
    public const CAPABILITIES = [
        'wifi.associate',
        'ble.witness',
        'ble.advertise',
        'background.residency',
        'camera.qr',
        'pose.track',
        'lidar.mesh',
        'depth.coarse',
        'uwb.ranging',
        'barometer',
    ];

    public const ROUTE_FIXED = 'fixed';
    public const ROUTE_POOL = 'pool';

    public ?string $name = null;
    public ?string $description = null;
    public string $audience = Quest::AUDIENCE_PUBLIC;
    public ?\DateTimeImmutable $availableFrom = null;
    public ?\DateTimeImmutable $availableTo = null;
    public float $points = 0.0;
    public ?int $estimatedDuration = null;

    public const SCOPE_UNLIMITED = 'unlimited';

    /** SCOPE_UNLIMITED (no policy, the default) or a RecurrenceScope value. */
    public string $recurrenceScope = self::SCOPE_UNLIMITED;
    public ?int $recurrenceCooldownSeconds = null;

    /** @var list<string> */
    public array $requiredCapabilities = [];

    /** @var list<Device> */
    public array $armedDevices = [];

    public string $routeMode = self::ROUTE_FIXED;
    /** One route per line, keys comma-separated. */
    public string $routes = '';

    public bool $featureBroadcast = false;
    public bool $featureTrack = false;
    public bool $featureWitness = false;
    public bool $featureIlluminator = false;

    /** The step list as the island posts it: a JSON list of {name, type, config}. */
    public string $stepsJson = '[]';

    public static function fromQuest(Quest $quest): self
    {
        $d = new self();
        $d->name = $quest->getName();
        $d->description = $quest->getDescription();
        $d->audience = $quest->getAudience();
        $d->availableFrom = $quest->getAvailableFrom() !== null
            ? \DateTimeImmutable::createFromInterface($quest->getAvailableFrom()) : null;
        $d->availableTo = $quest->getAvailableTo() !== null
            ? \DateTimeImmutable::createFromInterface($quest->getAvailableTo()) : null;
        $d->points = $quest->getPoints();
        $d->estimatedDuration = $quest->getEstimatedDuration();

        $policy = $quest->getRecurrencePolicy();
        if ($policy !== null) {
            $d->recurrenceScope = $policy->scope->value;
            $d->recurrenceCooldownSeconds = $policy->cooldownSeconds;
        }

        $d->requiredCapabilities = array_values(array_filter(
            $quest->getRequiredCapabilities(),
            static fn ($c): bool => is_string($c) && in_array($c, self::CAPABILITIES, true),
        ));
        $d->armedDevices = $quest->getArmedDevices()->toArray();

        $routes = QuestSpecMapper::routesOf($quest->getRoutePolicy());
        $d->routeMode = $routes === [] ? self::ROUTE_FIXED : self::ROUTE_POOL;
        $d->routes = implode("\n", array_map(static fn (array $r): string => implode(', ', $r), $routes));

        foreach ($quest->getSteps() as $step) {
            if ($step->getType()?->value === 'start') {
                $features = (array) ($step->getConfig()['features'] ?? []);
                $d->featureBroadcast = ($features['broadcast'] ?? false) === true;
                $d->featureTrack = ($features['track'] ?? false) === true;
                $d->featureWitness = ($features['witness'] ?? false) === true;
                $d->featureIlluminator = ($features['illuminator'] ?? false) === true;
                break;
            }
        }

        return $d;
    }

    /**
     * Write the header onto the quest. Steps are not touched here; the caller merges
     * `features()` into the start step it builds from `stepsJson`.
     *
     * `requiredCapabilities` is the union of what was ticked and what the steps imply
     * (`$derived`, from QuestPreflight), the way `lab_quest_write` always adds the implied ones.
     *
     * @param list<string> $derived
     */
    public function applyTo(Quest $quest, array $derived = []): void
    {
        $quest->setName((string) $this->name);
        $quest->setDescription((string) $this->description);
        $quest->setAudience($this->audience);
        $quest->setAvailableFrom(\DateTime::createFromImmutable($this->availableFrom ?? new \DateTimeImmutable()));
        $quest->setAvailableTo($this->availableTo !== null ? \DateTime::createFromImmutable($this->availableTo) : null);
        $quest->setPoints($this->points);
        $quest->setEstimatedDuration($this->estimatedDuration);
        $quest->setRecurrence($this->recurrence());
        $quest->setRequiredCapabilities(array_merge($this->requiredCapabilities, $derived));

        foreach ($quest->getArmedDevices()->toArray() as $device) {
            $quest->removeArmedDevice($device);
        }
        foreach ($this->armedDevices as $device) {
            $quest->addArmedDevice($device);
        }

        $routes = $this->parsedRoutes();
        $quest->setRoutePolicy($routes === [] ? null : ['mode' => 'pool', 'routes' => $routes]);
    }

    /** @return array{scope: string, cooldown_seconds: int}|null */
    public function recurrence(): ?array
    {
        $scope = RecurrenceScope::tryFrom($this->recurrenceScope);
        if ($scope === null) {
            return null;
        }

        return RecurrencePolicy::of($scope, max(0, (int) $this->recurrenceCooldownSeconds))->jsonSerialize();
    }

    /**
     * The routes textarea as a list of key lists; empty for fixed mode or a blank textarea.
     *
     * @return list<list<string>>
     */
    public function parsedRoutes(): array
    {
        if ($this->routeMode !== self::ROUTE_POOL) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', $this->routes) ?: [] as $line) {
            $keys = array_values(array_filter(array_map('trim', explode(',', $line)), static fn (string $k): bool => $k !== ''));
            if ($keys !== []) {
                $out[] = $keys;
            }
        }

        return $out;
    }

    /** @return array<string, bool> the start step's `features` block, every flag explicit */
    public function features(): array
    {
        $map = [
            'broadcast' => $this->featureBroadcast,
            'track' => $this->featureTrack,
            'witness' => $this->featureWitness,
            'illuminator' => $this->featureIlluminator,
        ];
        $out = [];
        foreach (StartSchema::FEATURES as $flag) {
            $out[$flag] = $map[$flag];
        }

        return $out;
    }
}
