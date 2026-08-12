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
}
