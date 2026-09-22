<?php

declare(strict_types=1);

namespace App\Lab\Contract;

/**
 * The canonical JSON bytes every IP-162 digest is taken over — the PHP side of one rule.
 *
 * The rule is stated once, in `monad-knowledge/monad_knowledge/lab/contracts/README.md`, and pinned
 * by `fixtures/canonical/vectors.json`: object keys sorted by Unicode code point, no whitespace,
 * non-ASCII written as itself, `/` unescaped, only the escapes JSON requires, literals as literals,
 * and NO floating-point numbers — three languages print doubles three ways. A non-integer quantity
 * is a decimal string.
 *
 * `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` matches the escape rules
 * (it writes `\u0001` for C0 controls and the short forms for `\b \f \n \r \t`); the key order is
 * applied here because `ksort` orders by byte string, which for UTF-8 keys IS code-point order.
 * Floats are refused before encoding because PHP would print them.
 */
final class CanonicalJson
{
    /**
     * @param mixed $document decoded JSON: arrays (assoc or list), scalars, null
     * @throws \InvalidArgumentException on a float anywhere in the document
     */
    public static function encode(mixed $document): string
    {
        $normalised = self::normalise($document, '$');
        $json = json_encode($normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $json;
    }

    /** Lower-case hex SHA-256 of {@see encode()}. */
    public static function sha256(mixed $document): string
    {
        return hash('sha256', self::encode($document));
    }

    /**
     * Digest of a JSON document as its sender canonicalised it: decoded with objects preserved,
     * so an empty `{}` is not mistaken for an empty `[]`. Use this over raw request bytes; use
     * {@see sha256()} over documents this backend built itself.
     */
    public static function sha256OfJson(string $rawJson): string
    {
        return self::sha256(json_decode($rawJson, false, 512, JSON_THROW_ON_ERROR));
    }

    /** Lower-case hex SHA-256 of raw bytes — how an uploaded TSV is hashed. Bytes, never rows. */
    public static function sha256Bytes(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /**
     * Digest of `$document` with its own `$field` removed: the rule behind every self-verifying
     * document (`protocol_sha256`, `frozen_sha256`).
     *
     * @param array<string, mixed> $document
     */
    public static function selfDigest(array $document, string $field): string
    {
        unset($document[$field]);

        return self::sha256($document);
    }

    /**
     * Sort every object's keys by code point and refuse floats. A PHP list (sequential integer
     * keys from 0) stays a JSON array; anything else is a JSON object.
     */
    private static function normalise(mixed $value, string $path): mixed
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException(
                sprintf('%s: floating-point numbers are not allowed in a hashed document; use an integer or a decimal string', $path),
            );
        }
        // A document decoded with objects preserved (`json_decode($raw, false)`) keeps `{}` and
        // `[]` apart, which an associative decode cannot: both become an empty array. The seal
        // hashes the raw request bytes through this path for exactly that reason.
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return new \stdClass();
            }
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $i => $item) {
                $out[] = self::normalise($item, sprintf('%s[%d]', $path, $i));
            }

            return $out;
        }
        $keys = array_map('strval', array_keys($value));
        // strcmp is byte order, and UTF-8 byte order is code-point order.
        usort($keys, 'strcmp');
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::normalise($value[$key], $path . '.' . $key);
        }
        // An object that happens to have no keys must encode as {} and not [].
        if ($out === []) {
            return new \stdClass();
        }

        return $out;
    }
}
