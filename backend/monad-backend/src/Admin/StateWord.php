<?php

namespace App\Admin;

/**
 * A state is a WORD in one of three hues, never a Bootstrap badge and never a raw enum.
 *
 * The 2026-09-17 browser tour found `IN_PROGRESS`, `YES`, `Empty` and `Null` on the CRUD lists
 * beside "in progress" and "complete" on the bespoke pages — two vocabularies for one fact.
 * This is the single vocabulary. Three semantic hues and no more: live/ok is the signal cyan,
 * stale/warn the ochre, fault/bad the brick. EVERYTHING ELSE IS MUTED, carried by form rather
 * than by colour, because the interface publishes more than three states and colour cannot
 * carry them all (the public site's theme.py says the same thing about the fleet's five).
 *
 * The hue table is a decision list, not a guess: a word is here because someone decided what
 * it means to an operator reading a row. An unlisted word renders muted, which is the correct
 * default — "I have no opinion about this state" is a fact, and inventing a hue for it would
 * be worse than saying nothing.
 */
final class StateWord
{
    /** Live, arrived, in service. @var list<string> */
    private const OK = [
        'complete', 'completed', 'active', 'in service', 'sent', 'delivered', 'registered',
        'open', 'up', 'live', 'armed', 'yes', 'enabled', 'agrees',
    ];

    /** Needs a human, eventually. @var list<string> */
    private const WARN = [
        'new', 'invited', 'pending', 'queued', 'scheduled', 'draft', 'abandoned', 'skipped',
        'inactive', 'resting', 'stale', 'no sidecar', 'unreachable',
    ];

    /** The thing that should have happened did not. @var list<string> */
    private const BAD = [
        'failed', 'banned', 'error', 'conflict', 'blocked', 'interrupted', 'refused',
    ];

    /**
     * The word for a value, with `_` read as a space and the case dropped.
     *
     * `null` is an em dash rather than the word "null": a value that was never written is not
     * a state, and the CRUD lists printed a badge saying otherwise until this existed.
     */
    public static function word(mixed $value, ?string $whenTrue = null, ?string $whenFalse = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? ($whenTrue ?? 'yes') : ($whenFalse ?? 'no');
        }
        if ($value instanceof \BackedEnum) {
            $value = (string) $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        } elseif (is_array($value)) {
            // A roles array, a capability list: the words, in order, comma separated.
            return implode(', ', array_map(static fn ($v) => self::word($v), $value));
        } elseif (!is_scalar($value)) {
            return '—';
        }

        return strtolower(str_replace('_', ' ', (string) $value));
    }

    /** ok | warn | bad | muted for one already-normalised word. */
    public static function hue(string $word): string
    {
        if (in_array($word, self::OK, true)) {
            return 'ok';
        }
        if (in_array($word, self::WARN, true)) {
            return 'warn';
        }
        if (in_array($word, self::BAD, true)) {
            return 'bad';
        }

        return 'muted';
    }

    /**
     * The `<span>` an index or detail cell prints.
     *
     * Returned as HTML on purpose: EasyAdmin's text field template renders `formattedValue`
     * raw, which is how one `formatValue()` call replaces a badge with a word. The word is
     * escaped here, so a value from the database cannot carry markup into the page.
     */
    public static function render(mixed $value, ?string $whenTrue = null, ?string $whenFalse = null): string
    {
        $word = self::word($value, $whenTrue, $whenFalse);
        if ($word === '—') {
            return '<span class="state state-muted" title="No value">—</span>';
        }

        return sprintf(
            '<span class="state state-%s">%s</span>',
            self::hue($word),
            htmlspecialchars($word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
