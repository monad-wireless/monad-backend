<?php

declare(strict_types=1);

namespace App\Quest\Schema;

/**
 * One field of a step's `config`, as the quest builder renders it and the palette explains it.
 *
 * A value object, not a validator: `StepSchema::validate()` owns the rules, because several of
 * them are relations between fields (walk_to's "location or coordinates", connect_to_ap's
 * forbidden keys, probe's nested targets) that a per-field spec cannot express. The spec is what
 * the typed form is drawn from; the bounds and choices here are the same numbers the validation
 * enforces, so the form can refuse an impossible value before the round trip.
 */
final class FieldSpec
{
    public const KIND_STRING = 'string';
    public const KIND_INT = 'int';
    public const KIND_NUMBER = 'number';
    public const KIND_BOOL = 'bool';
    public const KIND_ENUM = 'enum';
    public const KIND_LIST = 'list';
    /** A list of `{value, label, room, kind}` objects picked from the placement mirror (probe). */
    public const KIND_TARGETS = 'targets';
    /** A named place, picked from the placement mirror or typed (walk_to). */
    public const KIND_LOCATION = 'location';

    public const KINDS = [
        self::KIND_STRING,
        self::KIND_INT,
        self::KIND_NUMBER,
        self::KIND_BOOL,
        self::KIND_ENUM,
        self::KIND_LIST,
        self::KIND_TARGETS,
        self::KIND_LOCATION,
    ];

    /**
     * @param list<string>|null $choices closed value list, KIND_ENUM only
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly bool $required = false,
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly ?array $choices = null,
        public readonly string $help = '',
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown field kind "%s" for field "%s".', $kind, $name));
        }
        if ($choices !== null && $kind !== self::KIND_ENUM) {
            throw new \InvalidArgumentException(sprintf('Field "%s" declares choices but is not an enum.', $name));
        }
    }
}
