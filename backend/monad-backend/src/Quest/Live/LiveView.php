<?php

declare(strict_types=1);

namespace App\Quest\Live;

/**
 * What the phone draws: a title and a list of widgets.
 *
 * The widget set is closed and versioned with {@see LiveProtocol}. Every input widget has an `id`;
 * its event carries that id back as `widget`, so an engine can tell a stale tap from a current one.
 * The phone keeps its own half-typed input while the id stays the same, which is why an id must
 * change when the question changes (`count-2`, not `count`).
 */
final class LiveView
{
    /** @var list<array<string, mixed>> */
    private array $widgets = [];

    public function __construct(
        public readonly string $phase,
        public readonly string $title,
        public readonly int $pollMs = 1000,
    ) {
    }

    /** @param 'body'|'hint'|'warning'|'success' $style */
    public function text(string $text, string $style = 'body'): self
    {
        return $this->add(['type' => 'text', 'text' => $text, 'style' => $style]);
    }

    /**
     * A hot-and-cold meter. `value` null means "nothing to say", which is not "cold".
     *
     * @param 'none'|'cold'|'cool'|'warm'|'hot' $tone
     * @param 'none'|'up'|'down'|'flat' $trend
     */
    public function meter(?float $value, string $label, string $tone = 'none', string $trend = 'none'): self
    {
        return $this->add([
            'type' => 'meter',
            'value' => $value === null ? null : round(max(0.0, min(1.0, $value)), 3),
            'label' => $label,
            'tone' => $tone,
            'trend' => $trend,
        ]);
    }

    /** @param list<array{label: string, value: string}> $items */
    public function stats(array $items): self
    {
        return $this->add(['type' => 'stats', 'items' => $items]);
    }

    /** Opens the camera on a tap. Event `scan` with the code as `value`. */
    public function scan(string $id, string $label, string $hint = ''): self
    {
        return $this->add(['type' => 'scan', 'id' => $id, 'label' => $label, 'hint' => $hint]);
    }

    /**
     * A counter. Event `answer` with an integer. `record: headcount` also writes the answer on the
     * recording as a headcount marker, so a number about people reaches the evidence file.
     */
    public function number(string $id, string $prompt, int $min, int $max, int $initial, ?string $record = null): self
    {
        return $this->add([
            'type' => 'number', 'id' => $id, 'prompt' => $prompt,
            'min' => $min, 'max' => $max, 'initial' => $initial, 'record' => $record,
        ]);
    }

    /**
     * One of a few answers. Event `answer` with the chosen value.
     *
     * @param list<array{value: string, label: string}> $options
     */
    public function choice(string $id, string $prompt, array $options): self
    {
        return $this->add(['type' => 'choice', 'id' => $id, 'prompt' => $prompt, 'options' => $options]);
    }

    /**
     * Stand still. Event `hold_done`. The phone brackets it with dwell markers, and with a waypoint
     * at `target` when there is one, exactly as a probe step does.
     *
     * @param array{value: string, label: string, room: string, kind: string}|null $target
     */
    public function hold(string $id, int $seconds, string $label, ?array $target = null): self
    {
        return $this->add(['type' => 'hold', 'id' => $id, 'seconds' => $seconds, 'label' => $label, 'target' => $target]);
    }

    /**
     * Event `tap`.
     *
     * @param 'primary'|'secondary' $style
     */
    public function button(string $id, string $label, string $style = 'primary'): self
    {
        return $this->add(['type' => 'button', 'id' => $id, 'label' => $label, 'style' => $style]);
    }

    public function progress(int $value, int $total, string $label): self
    {
        return $this->add(['type' => 'progress', 'value' => $value, 'total' => $total, 'label' => $label]);
    }

    /** @return array{title: string, widgets: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'widgets' => $this->widgets];
    }

    /** @param array<string, mixed> $widget */
    private function add(array $widget): self
    {
        $this->widgets[] = $widget;

        return $this;
    }
}
