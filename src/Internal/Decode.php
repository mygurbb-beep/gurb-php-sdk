<?php

declare(strict_types=1);

namespace Gurb\Internal;

/**
 * Tolerant readers for decoded JSON.
 *
 * @internal Not part of the public API. May change in any release.
 *
 * Why tolerant instead of strict: the API adds fields over time, and an SDK
 * that throws on an unexpected payload turns an additive backend deploy into an
 * outage for every host site. A missing string reads as null and the host's own
 * null check handles it — the same posture the TypeScript SDK gets for free by
 * not validating at runtime at all.
 */
final class Decode
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $data */
    public static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $data */
    public static function nullableStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        // Numeric strings are accepted because PHP JSON encoders on the other
        // side of a proxy sometimes stringify large integers.
        return \is_int($value) ? $value : (\is_numeric($value) ? (int) $value : $default);
    }

    /**
     * A decimal that may arrive as a JSON number OR as a string.
     *
     * `Consultant.price` is `DECIMAL(10,2)` and Postgres drivers hand decimals
     * back as strings often enough that a strict `is_float` check would read a
     * real price as "absent". Anything non-numeric reads as null, which is the
     * same tolerant posture the rest of this class takes.
     *
     * @param array<string, mixed> $data
     */
    public static function nullableFloat(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return \is_int($value) || \is_float($value) || (\is_string($value) && \is_numeric($value))
            ? (float) $value
            : null;
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }

    /**
     * A list of strings, with non-strings dropped rather than coerced.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function strList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter($value, \is_string(...)));
    }

    /**
     * A nested object, normalised to an array so callers never branch on shape.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function obj(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    /**
     * A nested object, kept NULL when the key is absent.
     *
     * The difference from `obj()` matters on the settings shapes: `homeWidgets`
     * is published as `null` when a community has never configured one, and an
     * empty array would say "configured, and empty" instead.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function nullableObj(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) ? $value : null;
    }

    /**
     * A list of nested objects, with non-array entries dropped.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter($value, \is_array(...)));
    }

    /**
     * A map of `string => bool`, with non-boolean values dropped.
     *
     * `pageVisibility` is the only shape shaped like this, and dropping rather
     * than coercing matters there: a stray `"false"` string coerced to `true`
     * would report a hidden menu section as visible.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, bool>
     */
    public static function boolMap(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $name => $flag) {
            if (\is_bool($flag)) {
                $map[(string) $name] = $flag;
            }
        }

        return $map;
    }
}
