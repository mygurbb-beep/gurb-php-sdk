<?php

declare(strict_types=1);

namespace Gurb;

/**
 * The permission catalogue, shipped as DATA rather than fetched.
 *
 * WHY IT IS EMBEDDED AND NOT A NETWORK CALL
 *
 * Every permission the platform defines, with Arabic and English labels. It is
 * platform metadata — it changes when Gurb ships a new permission, a few times
 * a year — not per-community state. So there is nothing to fetch, nothing to
 * cache, and no request to be slow: rendering a permission screen costs zero
 * network calls.
 *
 * An earlier attempt fetched this from `/permissions/available`. That endpoint
 * answers 401 to a community API key, so the method would have been dead on
 * arrival in every consumer. Embedding is not the fallback — it is the better
 * answer, and it removes a round trip from the hot path of a permissions UI.
 *
 * Regenerate on each release from the backend's own constants; `source()`
 * records where this snapshot came from.
 */
final class Permissions
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @return array<string, mixed> */
    private static function data(): array
    {
        if (self::$data === null) {
            $raw = \file_get_contents(__DIR__ . '/../resources/permission-catalog.json');
            /** @var array<string, mixed> $decoded */
            $decoded = \json_decode((string) $raw, true, flags: \JSON_THROW_ON_ERROR);
            self::$data = $decoded;
        }

        return self::$data;
    }

    /** Where this snapshot came from, for when a label looks wrong. */
    public static function source(): string
    {
        return (string) self::data()['source'];
    }

    /** How many permissions the platform defines. */
    public static function total(): int
    {
        return (int) self::data()['total'];
    }

    /**
     * The categories, in the platform's own order.
     *
     * @return list<array<string, mixed>>
     */
    public static function categories(): array
    {
        /** @var list<array<string, mixed>> $c */
        $c = self::data()['categories'];

        return $c;
    }

    /**
     * Every permission, flattened.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::categories() as $category) {
            foreach ($category['permissions'] as $permission) {
                $out[] = $permission;
            }
        }

        return $out;
    }

    /** One permission by id, or null when the catalogue does not define it. */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $permission) {
            if ($permission['id'] === $id) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * An id → label map, ready to render.
     *
     * KEEP A `?? $id` FALLBACK at the call site. A member can hold a permission
     * minted by a platform release newer than this catalogue, and a missing key
     * rendering as an empty string makes a permission vanish from the screen.
     *
     * @return array<string, string>
     */
    public static function labels(string $locale = 'ar'): array
    {
        $out = [];
        foreach (self::all() as $permission) {
            $out[$permission['id']] = $locale === 'ar'
                ? $permission['nameArabic']
                : $permission['name'];
        }

        return $out;
    }

    /**
     * `module:manage` SUBSUMES `module:create` and `module:moderate`.
     *
     * THIS IS THE ONE RULE THAT MAKES RAW COUNTS LIE. Every guard in Gurb
     * accepts `manage | create | moderate` for a module, so a COMMUNITY_ADMIN
     * holding `posts:manage` may create posts without holding `posts:create`.
     *
     * Counted naively a MODERATOR looks MORE powerful than a COMMUNITY_ADMIN —
     * 33 stored permissions against 29 — because the moderator is granted the
     * granular pair where the admin is granted the one that covers both. Any
     * screen that compares roles by list length states the opposite of the
     * truth.
     *
     * @param list<string> $held
     */
    public static function impliedBy(array $held, string $wanted): bool
    {
        if (\in_array($wanted, $held, true)) {
            return true;
        }

        $parts = \explode(':', $wanted, 2);
        if (\count($parts) !== 2 || $parts[1] === 'manage') {
            return false;
        }

        return \in_array($parts[0] . ':manage', $held, true);
    }

    /**
     * What a role can actually do — expanded, not as stored.
     *
     * This is the number to show a human.
     *
     * @return list<string>
     */
    public static function effectiveFor(string $role): array
    {
        /** @var array<string, list<string>> $defaults */
        $defaults = self::data()['roleDefaults'];
        $held = $defaults[$role] ?? [];

        $out = [];
        foreach (self::all() as $permission) {
            if (self::impliedBy($held, $permission['id'])) {
                $out[] = $permission['id'];
            }
        }

        return $out;
    }

    /**
     * The permissions an admin may grant to this role. Admin-only ones excluded.
     *
     * @return list<array<string, mixed>>
     */
    public static function assignableTo(string $role): array
    {
        return \array_values(\array_filter(
            self::all(),
            static fn (array $p): bool => \in_array($role, $p['assignableTo'], true),
        ));
    }

    /**
     * The catalogue grouped for display, marking what the role already has.
     *
     * One call gives a permission screen everything it needs: categories in the
     * platform's order, labels in the requested language, and per row whether
     * the role holds it by default and whether it may be granted at all.
     *
     * @return list<array<string, mixed>>
     */
    public static function forRole(string $role, string $locale = 'ar'): array
    {
        $effective = \array_flip(self::effectiveFor($role));

        $out = [];
        foreach (self::categories() as $category) {
            $rows = [];
            foreach ($category['permissions'] as $p) {
                $rows[] = [
                    'id' => $p['id'],
                    'label' => $locale === 'ar' ? $p['nameArabic'] : $p['name'],
                    'description' => $locale === 'ar' ? $p['descriptionArabic'] : $p['description'],
                    'held' => isset($effective[$p['id']]),
                    'assignable' => \in_array($role, $p['assignableTo'], true),
                ];
            }
            $out[] = [
                'id' => $category['id'],
                'label' => $locale === 'ar' ? $category['nameArabic'] : $category['name'],
                'permissions' => $rows,
            ];
        }

        return $out;
    }
}
