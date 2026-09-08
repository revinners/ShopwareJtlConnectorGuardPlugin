<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * One definition of "same value" and "value for the audit log" shared by the number guard,
 * the identity guard, the field guard and the address guard. Payload values are what the DAL
 * serializers produced (scalars, bools, binary ids, JSON-encoded strings for JSON columns,
 * PHP arrays for custom-field keys); current values are raw DB rows (strings, `1`/`0`, binary).
 */
final class Values
{
    private function __construct()
    {
    }

    /**
     * Scalar comparison as strings. Bools are compared as MySQL returns them (`1` / `0`).
     * null only equals null.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return self::scalar($a) === self::scalar($b);
    }

    /**
     * Custom-field values: scalars as in same(), arrays/objects by canonical JSON.
     */
    public static function sameJson(mixed $a, mixed $b): bool
    {
        if (!\is_array($a) && !\is_object($a) && !\is_array($b) && !\is_object($b)) {
            return self::same($a, $b);
        }

        return self::canonical($a) === self::canonical($b);
    }

    /**
     * Renders a value for the audit log. Whether a value is a binary id is decided by the
     * column name (storage columns ending in `_id`), never by the value's shape: a plain string
     * can coincidentally be exactly 16 bytes (e.g. "Schröder-Wagner"), and guessing from that
     * would corrupt the audit record. Arrays (custom-field structures) are rendered as JSON.
     */
    public static function render(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (str_ends_with($field, '_id') && \is_string($value) && \strlen($value) === 16) {
            return Uuid::fromBytesToHex($value);
        }

        if (\is_array($value) || \is_object($value)) {
            return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: null;
        }

        return self::scalar($value);
    }

    public static function hexOrNull(mixed $bytes): ?string
    {
        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }

    /**
     * Decodes a JSON column value (as returned by DBAL) into an array; anything unreadable is [].
     *
     * @return array<string, mixed>
     */
    public static function decodeJson(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private static function scalar(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private static function canonical(mixed $value): string
    {
        if (\is_array($value)) {
            ksort($value);
            foreach ($value as $k => $v) {
                if (\is_array($v)) {
                    $value[$k] = json_decode(self::canonical($v), true);
                }
            }
        }

        return json_encode($value, \JSON_UNESCAPED_UNICODE) ?: '';
    }
}
