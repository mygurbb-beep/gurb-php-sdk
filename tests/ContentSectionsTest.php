<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbClient;
use Gurb\Model\Award;
use Gurb\Model\Consultant;
use Gurb\Model\Group;
use Gurb\Model\Project;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The five content sections added alongside tweets/events/blogs/albums.
 *
 * The interesting assertions here are not "does it call the right URL" — they
 * are the two fields that are ALWAYS null today (`Consultant::$title`,
 * `Award::$awardedAt`) and must decode as null rather than as an empty string.
 * That distinction is the contract: null says "there is nothing here", "" says
 * "there is something here and it is blank", and a UI branches differently on
 * each.
 */
final class ContentSectionsTest extends TestCase
{
    private const KEY = ApiKeyTest::KEY;

    /** @param array<string, mixed> $data */
    private function clientReturning(array $data, ?StubHttpClient &$http = null): GurbClient
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => $data]);

        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    /** One page envelope around a single row. */
    private function page(array $row): array
    {
        return ['items' => [$row], 'page' => 1, 'limit' => 20, 'total' => 1, 'hasMore' => false];
    }

    // ─── Groups ──────────────────────────────────────────────────────────────

    #[Test]
    public function groups_are_listed_from_the_groups_path(): void
    {
        $client = $this->clientReturning($this->page([
            'id' => 'grp_1',
            'name' => 'فريق التطوير',
            'description' => null,
            'coverUrl' => null,
            'memberCount' => 12,
            'isPrivate' => false,
            'createdAt' => '2026-08-01T10:00:00.000Z',
        ]), $http);

        $page = $client->groups->list(page: 2, limit: 5);

        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/groups?page=2&limit=5', $http->lastCall()->url);
        self::assertNull($http->lastCall()->body, 'a list carries no request body');

        $group = $page->items[0];
        self::assertInstanceOf(Group::class, $group);
        self::assertSame('grp_1', $group->id);
        self::assertSame('فريق التطوير', $group->name);
        self::assertNull($group->description);
        self::assertSame(12, $group->memberCount);
        self::assertFalse($group->isPrivate);
        self::assertSame('2026-08-01T10:00:00.000Z', $group->createdAt);
    }

    #[Test]
    public function a_private_group_is_returned_rather_than_filtered_away(): void
    {
        // An SDK caller acts FOR the community, so its own private groups are
        // its business. Publishing the flag instead of dropping the row is what
        // lets a caller decide; a filtered list would silently be half a list.
        $client = $this->clientReturning($this->page([
            'id' => 'grp_2',
            'name' => 'الإدارة',
            'description' => 'خاص',
            'coverUrl' => null,
            'memberCount' => 3,
            'isPrivate' => true,
            'createdAt' => '2026-08-02T10:00:00.000Z',
        ]));

        $group = $client->groups->list()->items[0];

        self::assertTrue($group->isPrivate);
        self::assertSame('الإدارة', $group->name, 'the private group is present, not omitted');
    }

    #[Test]
    public function an_unreadable_visibility_flag_closes_rather_than_opens(): void
    {
        // Every fallback in this SDK errs safely. For visibility, safe is
        // closed: a group wrongly shown as private is a missing card, one
        // wrongly shown as public is a leak.
        $client = $this->clientReturning($this->page([
            'id' => 'grp_3',
            'name' => 'Unknown',
            'memberCount' => 1,
            'createdAt' => '2026-08-03T10:00:00.000Z',
        ]));

        self::assertTrue($client->groups->list()->items[0]->isPrivate);
    }

    // ─── Consultants ─────────────────────────────────────────────────────────

    #[Test]
    public function consultants_are_listed_from_the_consultants_path(): void
    {
        $client = $this->clientReturning($this->page([
            'id' => 'con_1',
            'displayName' => 'استشارة قانونية',
            'title' => null,
            'bio' => 'وصف الخدمة',
            'avatarUrl' => 'https://cdn.test/a.png',
            'specialties' => ['قانون', 'عقود'],
            'createdAt' => '2026-08-04T10:00:00.000Z',
        ]), $http);

        $consultant = $client->consultants->list()->items[0];

        self::assertSame('https://x.test/api/sdk/consultants', $http->lastCall()->url);
        self::assertInstanceOf(Consultant::class, $consultant);
        self::assertSame('con_1', $consultant->id);
        self::assertSame('استشارة قانونية', $consultant->displayName);
        self::assertSame('وصف الخدمة', $consultant->bio);
        self::assertSame(['قانون', 'عقود'], $consultant->specialties);
    }

    #[Test]
    public function a_consultant_title_decodes_to_null_and_never_to_an_empty_string(): void
    {
        // ALWAYS null today: the table models an offered service and has no
        // title column. Collapsing that to "" would claim the listing has a
        // blank title, which is a different and false statement — and it would
        // make `$c->title ?? 'Consultant'` silently stop working.
        $client = $this->clientReturning($this->page([
            'id' => 'con_2',
            'displayName' => 'استشارة',
            'title' => null,
            'bio' => null,
            'avatarUrl' => null,
            'specialties' => [],
            'createdAt' => '2026-08-05T10:00:00.000Z',
        ]));

        $consultant = $client->consultants->list()->items[0];

        self::assertNull($consultant->title);
        self::assertNotSame('', $consultant->title, 'null, not an empty string');
        self::assertNull($consultant->bio);
        self::assertNull($consultant->avatarUrl);
        self::assertSame([], $consultant->specialties, 'a relation with no rows is an empty list');
    }

    #[Test]
    public function a_title_the_backend_omits_entirely_is_also_null(): void
    {
        // Today the field is sent as null. When the column arrives it will carry
        // a string. A build that omits it must not become "" either.
        $client = $this->clientReturning($this->page([
            'id' => 'con_3',
            'displayName' => 'استشارة',
            'specialties' => [],
            'createdAt' => '2026-08-06T10:00:00.000Z',
        ]));

        self::assertNull($client->consultants->list()->items[0]->title);
    }

    #[Test]
    public function a_non_string_specialty_is_dropped_rather_than_coerced(): void
    {
        $client = $this->clientReturning($this->page([
            'id' => 'con_4',
            'displayName' => 'استشارة',
            'specialties' => ['قانون', 42, null],
            'createdAt' => '2026-08-07T10:00:00.000Z',
        ]));

        self::assertSame(['قانون'], $client->consultants->list()->items[0]->specialties);
    }

    // ─── Projects, and the parallel second module ────────────────────────────

    #[Test]
    public function projects_are_listed_from_the_projects_path(): void
    {
        $client = $this->clientReturning($this->page([
            'id' => 'prj_1',
            'title' => 'مشروع',
            'description' => null,
            'coverUrl' => null,
            'status' => 'ACTIVE',
            'createdAt' => '2026-08-08T10:00:00.000Z',
        ]), $http);

        $project = $client->projects->list(limit: 3)->items[0];

        self::assertSame('https://x.test/api/sdk/projects?limit=3', $http->lastCall()->url);
        self::assertInstanceOf(Project::class, $project);
        self::assertSame('ACTIVE', $project->status);
    }

    #[Test]
    public function projects2_is_a_separate_endpoint_and_not_a_version_of_projects(): void
    {
        // The failure this pins down: quietly pointing projects2 at sdk/projects
        // would make both accessors return the same list, and a community that
        // runs only the second module would read as having no projects at all.
        $client = $this->clientReturning($this->page([
            'id' => 'prj_2',
            'title' => 'مشروع ثانٍ',
            'description' => null,
            'coverUrl' => null,
            'status' => null,
            'createdAt' => '2026-08-09T10:00:00.000Z',
        ]), $http);

        $project = $client->projects2->list()->items[0];

        self::assertSame('https://x.test/api/sdk/projects2', $http->lastCall()->url);
        self::assertInstanceOf(Project::class, $project, 'the same shape from both modules');
        self::assertSame('prj_2', $project->id);
    }

    #[Test]
    public function a_project_status_is_free_form_and_nullable(): void
    {
        // Not an enum: the platform publishes no closed set and each community
        // words its own, so an unseen string must decode rather than throw.
        $client = $this->clientReturning($this->page([
            'id' => 'prj_3',
            'title' => 'مشروع',
            'description' => null,
            'coverUrl' => null,
            'status' => 'قيد المراجعة',
            'createdAt' => '2026-08-10T10:00:00.000Z',
        ]));
        self::assertSame('قيد المراجعة', $client->projects->list()->items[0]->status);

        $client = $this->clientReturning($this->page([
            'id' => 'prj_4',
            'title' => 'مشروع',
            'status' => null,
            'createdAt' => '2026-08-10T10:00:00.000Z',
        ]));
        self::assertNull($client->projects->list()->items[0]->status);
    }

    // ─── Awards ──────────────────────────────────────────────────────────────

    #[Test]
    public function awards_are_listed_from_the_awards_path(): void
    {
        $client = $this->clientReturning($this->page([
            'id' => 'awd_1',
            'title' => 'وسام التميز',
            'description' => 'يُمنح سنوياً',
            'imageUrl' => 'https://cdn.test/medal.png',
            'awardedAt' => null,
            'createdAt' => '2026-08-11T10:00:00.000Z',
        ]), $http);

        $award = $client->awards->list(page: 1)->items[0];

        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/awards?page=1', $http->lastCall()->url);
        self::assertInstanceOf(Award::class, $award);
        self::assertSame('وسام التميز', $award->title);
        self::assertSame('https://cdn.test/medal.png', $award->imageUrl);
    }

    #[Test]
    public function an_award_awarded_at_decodes_to_null_and_never_to_an_empty_string(): void
    {
        // ALWAYS null on this endpoint. `sdk/awards` is the CATALOGUE of medals
        // a community defines; when one was granted belongs to the grant, not to
        // the definition. Empty-stringing it would put a falsy-but-present value
        // where consumers check `!== null`, and tempt someone to "repair" it
        // with createdAt — which is when the medal was defined, not awarded.
        $client = $this->clientReturning($this->page([
            'id' => 'awd_2',
            'title' => 'وسام',
            'description' => null,
            'imageUrl' => null,
            'awardedAt' => null,
            'createdAt' => '2026-08-12T10:00:00.000Z',
        ]));

        $award = $client->awards->list()->items[0];

        self::assertNull($award->awardedAt);
        self::assertNotSame('', $award->awardedAt, 'null, not an empty string');
        self::assertSame('2026-08-12T10:00:00.000Z', $award->createdAt, 'createdAt is real and is not a substitute');
        self::assertNotSame($award->createdAt, $award->awardedAt);
    }

    // ─── Shared envelope behaviour ───────────────────────────────────────────

    #[Test]
    public function every_new_section_returns_the_standard_pagination_envelope(): void
    {
        $sections = [
            'groups' => ['id' => 'g', 'name' => 'g', 'memberCount' => 0, 'createdAt' => 'x'],
            'consultants' => ['id' => 'c', 'displayName' => 'c', 'specialties' => [], 'createdAt' => 'x'],
            'projects' => ['id' => 'p', 'title' => 'p', 'createdAt' => 'x'],
            'projects2' => ['id' => 'p', 'title' => 'p', 'createdAt' => 'x'],
            'awards' => ['id' => 'a', 'title' => 'a', 'createdAt' => 'x'],
        ];

        foreach ($sections as $accessor => $row) {
            $client = $this->clientReturning([
                'items' => [$row, $row],
                'page' => 3,
                'limit' => 2,
                'total' => 9,
                'hasMore' => true,
            ]);

            $page = $client->{$accessor}->list();

            self::assertCount(2, $page, "{$accessor}: items");
            self::assertSame(3, $page->page, "{$accessor}: page");
            self::assertSame(2, $page->limit, "{$accessor}: limit");
            self::assertSame(9, $page->total, "{$accessor}: total");
            self::assertTrue($page->hasMore, "{$accessor}: hasMore");
        }
    }

    #[Test]
    public function each_new_section_has_its_own_path_and_they_do_not_collide(): void
    {
        $expected = [
            'groups' => 'https://x.test/api/sdk/groups',
            'consultants' => 'https://x.test/api/sdk/consultants',
            'projects' => 'https://x.test/api/sdk/projects',
            'projects2' => 'https://x.test/api/sdk/projects2',
            'awards' => 'https://x.test/api/sdk/awards',
        ];

        $seen = [];
        foreach ($expected as $accessor => $url) {
            $client = $this->clientReturning(['items' => []], $http);
            $client->{$accessor}->list();

            self::assertSame($url, $http->lastCall()->url, "{$accessor}: path");
            $seen[] = $http->lastCall()->url;
        }

        self::assertSame($seen, \array_unique($seen), 'no two sections share a path');
    }
}
