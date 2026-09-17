<?php

namespace App\Twig;

use App\Admin\StateWord;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The three formatters every admin page needs, and the rule behind the first one.
 *
 * A PUBLISHED CLOCK IS AN INSTANT. The public site renders every timestamp from
 * the stored instant through one function (`web/localtime.py:clock()`) and never
 * through `strftime` on a UTC host. The admin follows the same rule: `clock`
 * takes the stored instant, converts it to the one operator timezone this
 * deployment declares, and prints the zone abbreviation beside it so the reader
 * never has to guess which clock a column is on. Rows written by phones carry
 * their own wall time in milliseconds; those go through `clock` too, after the
 * entity turns them into instants.
 */
final class AdminExtension extends AbstractExtension
{
    private \DateTimeZone $zone;

    public function __construct(string $timezone = 'UTC')
    {
        try {
            $this->zone = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (\Exception) {
            $this->zone = new \DateTimeZone('UTC');
        }
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('clock', [$this, 'clock']),
            new TwigFilter('bytes', [$this, 'bytes']),
            new TwigFilter('duration', [$this, 'duration']),
            new TwigFilter('short_id', [$this, 'shortId']),
            new TwigFilter('state_word', [$this, 'stateWord'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * An enum, a boolean or a status string as a state word in one of three hues.
     *
     * The same vocabulary the CRUD lists use (`App\Admin\StateWord`, reached from the field
     * definitions through `StateWordFields`), so a status reads identically whether the reader
     * is on a bespoke page or on an EasyAdmin index. Marked html-safe because StateWord escapes
     * the word itself and nothing else in the string comes from data.
     */
    public function stateWord(mixed $value, ?string $whenTrue = null, ?string $whenFalse = null): string
    {
        return StateWord::render($value, $whenTrue, $whenFalse);
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_timezone', [$this, 'timezoneName']),
        ];
    }

    /**
     * The zone every clock on this interface is printed in.
     *
     * The shell states it once in the rail and Settings prints it as a fact, so a reader never
     * has to infer the zone from an abbreviation. Same value the `clock` filter converts to,
     * from the same object, because two sources would eventually disagree.
     */
    public function timezoneName(): string
    {
        return $this->zone->getName();
    }

    /** `2026-09-04 12:05:31 CEST`, or an em dash for nothing. */
    public function clock(\DateTimeInterface|int|null $instant, string $format = 'Y-m-d H:i:s'): string
    {
        if ($instant === null) {
            return '—';
        }
        if (is_int($instant)) {
            // Unix milliseconds when it is too large to be seconds. Phones write wall_ms.
            $seconds = $instant > 100_000_000_000 ? intdiv($instant, 1000) : $instant;
            $instant = new \DateTimeImmutable('@' . $seconds);
        }
        $local = \DateTimeImmutable::createFromInterface($instant)->setTimezone($this->zone);

        return $local->format($format) . ' ' . $local->format('T');
    }

    /** `102.9 MB`, `800 B`. Decimal units, one decimal above kB. */
    public function bytes(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $bytes = (float) $bytes;
        foreach (['B', 'kB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1000 || $unit === 'TB') {
                return $i === 0 ? sprintf('%d %s', $bytes, $unit) : sprintf('%.1f %s', $bytes, $unit);
            }
            $bytes /= 1000;
        }

        return sprintf('%.1f TB', $bytes);
    }

    /** `12 min 05 s`, `3 h 02 min`, `41 s`. */
    public function duration(int|float|null $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $seconds = (int) round($seconds);
        if ($seconds < 60) {
            return sprintf('%d s', $seconds);
        }
        if ($seconds < 3600) {
            return sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /** The first eight characters — how the public site names a walk. */
    public function shortId(mixed $id): string
    {
        $s = (string) $id;

        return strlen($s) > 8 ? substr($s, 0, 8) : $s;
    }
}
