<?php

declare(strict_types=1);

namespace App\Quest\Live;

/** One thing the phone reported. `kind` is closed per widget: scan, answer, tap, hold_done. */
final class LiveEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly array $data = [],
        public readonly ?int $clientWallMs = null,
        public readonly ?int $clientMonoNs = null,
    ) {
    }

    public function widget(): string
    {
        return is_string($this->data['widget'] ?? null) ? $this->data['widget'] : '';
    }

    public function text(): ?string
    {
        $value = $this->data['value'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function integer(): ?int
    {
        $value = $this->data['value'] ?? null;

        return is_int($value) ? $value : null;
    }
}
