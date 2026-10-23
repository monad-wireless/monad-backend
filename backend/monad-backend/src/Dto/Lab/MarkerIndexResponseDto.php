<?php

namespace App\Dto\Lab;

/**
 * What `GET /api/lab/marker-index` says to the world (IP-129 §2.4).
 *
 * A hand-built allowlist rather than a pass-through of `MarkerService::markers()`, for the same
 * reason `DevicePublicResponseDto` is one: what is public gets decided in one readable place, so
 * a field added to the projection later cannot publish itself. Two things in particular must never
 * arrive here, and neither is filtered downstream:
 *
 * - **A step's `config`.** It carries `expected_value`, and handing an anonymous caller the
 *   answer key makes the ground-truth channel — the only stream that counts people rather than
 *   phones — forgeable without walking anywhere (see QuestStepDtoTest).
 * - **`QrCode.position`.** That is where a card physically is. The card itself does not say, on
 *   purpose: the fingerprint set is re-laid between arms, so the placement inventory is
 *   admin-only and the public page prints no location.
 *
 * `value` is published because it is what the portal matches a scanned code against, and it is
 * already a public fact: it is printed, in full, on cards taped to walls in a public building.
 */
final class MarkerIndexResponseDto
{
    /**
     * @param list<array<string, mixed>> $markers as returned by MarkerService::markers()
     */
    public function __construct(
        private readonly array $markers,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'markers' => array_map(
                static fn (array $marker): array => [
                    'value' => (string) ($marker['value'] ?? ''),
                    'label' => (string) ($marker['label'] ?? ''),
                    'quests' => array_map(
                        static fn (array $quest): array => [
                            // The id is what the portal links to; the name alone is not a key.
                            'id' => (string) ($quest['id'] ?? ''),
                            'name' => (string) ($quest['name'] ?? ''),
                            'description' => $quest['description'] ?? null,
                            'points' => (float) ($quest['points'] ?? 0),
                            'estimated_duration' => $quest['estimated_duration'] ?? null,
                        ],
                        array_values((array) ($marker['quests'] ?? [])),
                    ),
                ],
                $this->markers,
            ),
        ];
    }
}
