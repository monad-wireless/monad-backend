<?php

namespace App\Dto\Lab;

/**
 * What `GET /api/lab/arming-matrix` says to the world (IP-129 §4.2).
 *
 * A hand-built allowlist over a projection the controller assembles, for the same reason
 * `MarkerIndexResponseDto` is one: the route is PUBLIC_ACCESS, nothing filters it downstream, so
 * a field that reaches this array reaches the internet. Deciding that in one readable place is
 * what stops a column added to `Quest` or `Device` later from publishing itself.
 *
 * Three things must never arrive here:
 *
 * - **A step's `config`.** It carries `expected_value`, the answer key to the ground-truth
 *   channel — the only stream that counts people rather than phones (see QuestStepDtoTest). This
 *   projection carries no steps at all, which is the strongest form of that guarantee.
 * - **`QrCode.position`**, or any other placement. Where a card is lives in pen on the card and
 *   in the admin-only inventory.
 * - **`Device.radioMac`.** A public slug -> MAC mapping is a global, permanent, indexable
 *   disclosure; the label in someone's hand is a physically-scoped one.
 *
 * What it *does* carry is the thing the ops view exists for: an availability reason for every
 * quest x device cell, including the three `QuestAvailabilityFilter::isHidden()` removes from a
 * participant's list. A blank cell that could mean "outside its window" or "not armed here" or
 * "node out of service" is worse than no matrix at all — the researcher checking arming from a
 * corridor would read the first as the second.
 */
final class ArmingMatrixResponseDto
{
    /**
     * @param list<array<string, mixed>> $quests as ArmingMatrixController assembles them
     */
    public function __construct(
        private readonly array $quests,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'quests' => array_map(
                static fn (array $quest): array => [
                    'id' => (string) ($quest['id'] ?? ''),
                    'name' => (string) ($quest['name'] ?? ''),
                    'description' => $quest['description'] ?? null,
                    'points' => (float) ($quest['points'] ?? 0),
                    'estimated_duration' => $quest['estimated_duration'] ?? null,
                    // Both ends of the window, always present, `null` for open-ended. An absent
                    // key would be read as "no window" by a client that never learned the
                    // difference between unset and unbounded.
                    'available_from' => $quest['available_from'] ?? null,
                    'available_to' => $quest['available_to'] ?? null,
                    'recurrence' => $quest['recurrence'] ?? null,
                    'required_capabilities' => array_values(
                        array_map('strval', (array) ($quest['required_capabilities'] ?? [])),
                    ),
                    'devices' => array_map(
                        static fn (array $cell): array => [
                            'slug' => (string) ($cell['slug'] ?? ''),
                            'armed' => (bool) ($cell['armed'] ?? false),
                            // The unfiltered assessment. `reason` is null exactly when
                            // `available` is true, so a cell always says something.
                            'availability' => [
                                'available' => (bool) (($cell['availability']['available'] ?? false)),
                                'reason' => $cell['availability']['reason'] ?? null,
                                'retry_at' => $cell['availability']['retry_at'] ?? null,
                            ],
                        ],
                        array_values((array) ($quest['devices'] ?? [])),
                    ),
                ],
                $this->quests,
            ),
        ];
    }
}
