<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\Permissions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The permission catalogue — the same assertions as the TypeScript suite.
 *
 * Both SDKs ship the SAME generated JSON, so these tests are deliberately a
 * mirror: if one suite passes and the other fails, the two SDKs have drifted,
 * which is the failure this whole arrangement exists to prevent.
 */
final class PermissionsTest extends TestCase
{
    #[Test]
    public function it_carries_every_permission_the_platform_defines(): void
    {
        self::assertCount(Permissions::total(), Permissions::all());
        self::assertSame(48, Permissions::total());
        self::assertCount(26, Permissions::categories());
    }

    /**
     * A blank label renders as an empty cell, which reads as "this permission
     * does nothing" rather than as a gap. This assertion already caught a real
     * bug: the generator matched single-quoted strings only, and silently
     * dropped seven descriptions written with double quotes because they
     * contain an apostrophe.
     */
    #[Test]
    public function every_row_has_both_languages_with_no_blanks(): void
    {
        $blank = [];
        foreach (Permissions::all() as $p) {
            if ($p['name'] === '' || $p['nameArabic'] === ''
                || $p['description'] === '' || $p['descriptionArabic'] === '') {
                $blank[] = $p['id'];
            }
        }

        self::assertSame([], $blank);
    }

    #[Test]
    public function it_has_no_duplicate_ids_across_categories(): void
    {
        $ids = \array_column(Permissions::all(), 'id');
        self::assertSame(\count($ids), \count(\array_unique($ids)));
    }

    #[Test]
    public function every_id_is_shaped_module_colon_verb(): void
    {
        foreach (Permissions::all() as $p) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+:[a-z0-9_]+$/', $p['id']);
        }
    }

    #[Test]
    public function labels_answer_arabic_by_default_and_english_on_request(): void
    {
        $one = Permissions::find('users:manage');
        self::assertNotNull($one);

        self::assertSame($one['nameArabic'], Permissions::labels()['users:manage']);
        self::assertSame($one['name'], Permissions::labels('en')['users:manage']);
    }

    #[Test]
    public function labels_cover_every_id(): void
    {
        $labels = Permissions::labels();
        foreach (Permissions::all() as $p) {
            self::assertArrayHasKey($p['id'], $labels);
        }
    }

    // ─── manage subsumes create and moderate ─────────────────────────────────

    /**
     * THE RULE THAT MAKES RAW COUNTS LIE.
     *
     * A COMMUNITY_ADMIN is granted `posts:manage`; a MODERATOR is granted
     * `posts:create` AND `posts:moderate`. Counted naively the moderator looks
     * more powerful, and any screen comparing roles by list length tells the
     * reader the opposite of the truth.
     */
    #[Test]
    public function the_admin_resolves_to_more_capability_than_the_moderator(): void
    {
        $admin = Permissions::effectiveFor('COMMUNITY_ADMIN');
        $mod = Permissions::effectiveFor('MODERATOR');

        // Once `manage` is expanded, the admin is genuinely broader — even
        // though the STORED lists say the opposite (29 against 33). That raw
        // comparison is pinned in the TypeScript suite, which reads the stored
        // arrays directly; here the expansion is what is under test.
        self::assertGreaterThan(\count($mod), \count($admin));
    }

    #[Test]
    public function manage_grants_create_and_moderate(): void
    {
        self::assertTrue(Permissions::impliedBy(['posts:manage'], 'posts:create'));
        self::assertTrue(Permissions::impliedBy(['posts:manage'], 'posts:moderate'));
    }

    #[Test]
    public function create_never_implies_manage(): void
    {
        self::assertFalse(Permissions::impliedBy(['posts:create'], 'posts:manage'));
    }

    #[Test]
    public function implication_never_leaks_across_modules(): void
    {
        self::assertFalse(Permissions::impliedBy(['posts:manage'], 'blogs:create'));
    }

    #[Test]
    public function the_admin_gains_create_permissions_it_was_never_granted_directly(): void
    {
        self::assertContains('posts:create', Permissions::effectiveFor('COMMUNITY_ADMIN'));
    }

    /** Not a bug and not a gap — every capability is granted individually. */
    #[Test]
    public function a_plain_member_holds_nothing_at_all(): void
    {
        self::assertSame([], Permissions::effectiveFor('MEMBER'));
    }

    // ─── what an admin may grant ─────────────────────────────────────────────

    #[Test]
    public function a_moderator_may_be_granted_a_real_subset(): void
    {
        $assignable = Permissions::assignableTo('MODERATOR');

        self::assertNotEmpty($assignable);
        self::assertLessThan(Permissions::total(), \count($assignable));
    }

    #[Test]
    public function an_admin_only_permission_is_offered_to_nobody(): void
    {
        $moderator = \array_column(Permissions::assignableTo('MODERATOR'), 'id');
        $member = \array_column(Permissions::assignableTo('MEMBER'), 'id');

        foreach (Permissions::all() as $p) {
            if ($p['assignableTo'] === []) {
                self::assertNotContains($p['id'], $moderator);
                self::assertNotContains($p['id'], $member);
            }
        }
    }

    // ─── forRole — one call for a whole screen ───────────────────────────────

    #[Test]
    public function for_role_returns_every_category_in_platform_order_with_labels(): void
    {
        $view = Permissions::forRole('MODERATOR');
        $first = Permissions::categories()[0];

        self::assertCount(26, $view);
        self::assertSame($first['nameArabic'], $view[0]['label']);
        self::assertSame($first['name'], Permissions::forRole('MODERATOR', 'en')[0]['label']);
    }

    #[Test]
    public function for_role_marks_held_rows_from_the_expanded_set(): void
    {
        $flat = [];
        foreach (Permissions::forRole('COMMUNITY_ADMIN') as $c) {
            foreach ($c['permissions'] as $p) {
                $flat[$p['id']] = $p['held'];
            }
        }

        // Never granted directly; held because `posts:manage` implies it.
        self::assertTrue($flat['posts:create']);
    }

    #[Test]
    public function for_role_marks_nothing_held_for_a_plain_member(): void
    {
        foreach (Permissions::forRole('MEMBER') as $c) {
            foreach ($c['permissions'] as $p) {
                self::assertFalse($p['held'], $p['id'] . ' should not be held');
            }
        }
    }

    #[Test]
    public function for_role_never_loses_a_permission(): void
    {
        $count = 0;
        foreach (Permissions::forRole('MEMBER') as $c) {
            $count += \count($c['permissions']);
        }

        self::assertSame(Permissions::total(), $count);
    }

    #[Test]
    public function it_records_where_the_snapshot_came_from(): void
    {
        self::assertStringContainsString('permissions', Permissions::source());
    }
}
