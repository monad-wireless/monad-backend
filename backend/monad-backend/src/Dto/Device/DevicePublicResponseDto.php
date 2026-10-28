<?php

namespace App\Dto\Device;

use App\Entity\Device;

/**
 * What `GET /api/device/{slug}` says to the world (IP-128).
 *
 * A hand-built projection rather than serializer groups on the entity, so that
 * what is public is decided in one readable place and adding a column to
 * `Device` can never silently publish it.
 *
 * NOT INCLUDED, and each for a reason:
 *
 * - `radioMac` — the sticker already discloses it to whoever stands next to the
 *   box, which is physically scoped. A public slug→MAC endpoint is global,
 *   permanent, indexable, and hands over an address to spoof or filter on.
 * - Telemetry — owned by Mimir and read live by the portal. A copy here would be
 *   a second, permanently stale source of truth.
 * - Anything per-participant — this endpoint is unauthenticated. Availability
 *   that depends on *who* is asking is computed on the authenticated path.
 */
final class DevicePublicResponseDto
{
    /**
     * @param array<int, array<string, mixed>> $quests
     */
    public function __construct(
        private readonly Device $device,
        private readonly array $quests,
        private readonly int $fleetSize,
        /** Distinct completed participants here, RAW. Floored below. */
        private readonly int $participantsHere = 0,
        /** k-anonymity floor: below this the count is suppressed, not rounded. */
        private readonly int $communityFloor = 5,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'device' => [
                'slug' => $this->device->getSlug(),
                'label' => $this->device->getLabel(),
                'location' => $this->device->getLocation(),
                'site_ref' => $this->device->getSiteRef(),
                'public_blurb' => $this->device->getPublicBlurb(),
                'is_active' => $this->device->isActive(),
                'commissioned_at' => $this->device->getCommissionedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'quests' => $this->quests,
            'fleet' => [
                // The passport denominator, so the app never hardcodes "12".
                'active_devices' => $this->fleetSize,
            ],
            // Suppressed rather than rounded below the floor. On a fleet where a
            // node may have been visited by two people, "2" plus a timestamp plus
            // a location is close to naming them; null says nothing at all.
            'community' => [
                'participants_here' => $this->participantsHere >= $this->communityFloor
                    ? $this->participantsHere
                    : null,
                'floor' => $this->communityFloor,
            ],
        ];
    }
}
