<?php

namespace App\Quest;

use App\Entity\Quest;
use App\Repository\DeviceRepository;
use App\Repository\QuestRepository;

/**
 * "What is armed where, and why not?" — the projection behind two readers (IP-129 §4.2, IP-149).
 *
 * Moved out of `ArmingMatrixController` when the admin gained a page for it, so
 * the public JSON and the operator's table are one computation. Two readers of one
 * builder cannot disagree about whether a quest is armed at a node; two loops could.
 *
 * THE ASSESSMENT IS UNFILTERED, AND THAT IS THE WHOLE POINT. `QuestAvailabilityFilter`
 * removes `window_closed`, `not_armed` and `device_inactive` from a participant's
 * list, which is right for a stranger standing in front of a box. Here every reason
 * survives: an empty cell that could mean four different things is useless to the
 * researcher checking arming from a corridor before a session.
 */
final class ArmingMatrixBuilder
{
    public function __construct(
        private readonly QuestRepository $quests,
        private readonly DeviceRepository $devices,
        private readonly QuestArmingService $arming,
    ) {
    }

    /**
     * One row per quest, one cell per device — EVERY device, including inactive ones,
     * because `device_inactive` is a cell state the view must be able to show.
     *
     * @return array{devices: list<string>, rows: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $devices = $this->devices->findBy([], ['slug' => 'ASC']);

        $rows = [];
        foreach ($this->quests->findAll() as $quest) {
            if (!$quest instanceof Quest) {
                continue;
            }

            $requiresCapture = QuestArmingService::producesMeasurement($quest);

            $cells = [];
            foreach ($devices as $device) {
                // No user: this describes the world — window, arming, node state — never a
                // participant's history. `retry_at` is therefore always null here.
                $availability = $this->arming->assess(
                    quest: $quest,
                    user: null,
                    device: $device,
                    requiresCapture: $requiresCapture,
                );

                $cells[] = [
                    'slug' => $device->getSlug(),
                    // Not derived from the reason: a quest armed at a node can still be blocked
                    // by its window, and the view has to show arming and blocking as the two
                    // independent facts they are.
                    'armed' => $quest->isArmedAt($device),
                    'availability' => $availability->jsonSerialize(),
                ];
            }

            $rows[] = [
                'id' => (string) $quest->getId(),
                'name' => $quest->getName(),
                'description' => $quest->getDescription(),
                'points' => $quest->getPoints(),
                'estimated_duration' => $quest->getEstimatedDuration(),
                'available_from' => $quest->getAvailableFrom()?->format(\DateTimeInterface::ATOM),
                'available_to' => $quest->getAvailableTo()?->format(\DateTimeInterface::ATOM),
                'recurrence' => $quest->getRecurrencePolicy()?->jsonSerialize(),
                'required_capabilities' => $quest->getRequiredCapabilities(),
                'audience' => $quest->getAudience(),
                'produces_measurement' => $requiresCapture,
                'devices' => $cells,
            ];
        }

        return [
            'devices' => array_map(static fn ($d) => (string) $d->getSlug(), $devices),
            'rows' => $rows,
        ];
    }
}
