<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Groups, consultants, projects, projects-2 and awards — the wire contract.
 *
 * WHY THESE ASSERTIONS AND NOT "does it work"
 *
 * Every method here is a PUBLISHED CONTRACT. Once a customer's integration calls
 * it, changing a path or a verb breaks their production silently, at a moment we
 * do not choose and cannot roll back for them. Pinning the verb and the URL in a
 * test means such a change fails here instead of there.
 *
 * These assertions are deliberately IDENTICAL to the ones in the TypeScript
 * SDK's suite for the same endpoints. The two libraries describe ONE contract,
 * and the only thing that stops them drifting apart is that both are pinned to
 * the same claims. If you change one file, change the other in the same commit.
 */
final class SectionWritesTest extends TestCase
{
    private const KEY = 'gurb_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function clientReturning(array $data, ?StubHttpClient &$http = null): GurbClient
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => $data]);

        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    // ─── Verb and path ───────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('createPaths')]
    public function each_section_creates_at_its_own_path(string $section, string $path): void
    {
        $client = $this->clientReturning($this->rowFor($section), $http);

        match ($section) {
            'groups' => $client->groups->create('فريق', 'وصف', false, 'https://cdn.test/i.png'),
            'consultants' => $client->consultants->create('استشارة', 'https://book.test/x'),
            'projects' => $client->projects->create('Bridge', 'A bridge'),
            'projects2' => $client->projects2->create('Bridge', 'A bridge'),
            'awards' => $client->awards->create('Gold', 'trophy', '#093431'),
        };

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame("https://x.test/api/{$path}", $http->lastCall()->url);
    }

    public static function createPaths(): array
    {
        return [
            'groups' => ['groups', 'sdk/groups'],
            'consultants' => ['consultants', 'sdk/consultants'],
            'projects' => ['projects', 'sdk/projects'],
            'projects2' => ['projects2', 'sdk/projects2'],
            'awards' => ['awards', 'sdk/awards'],
        ];
    }

    #[Test]
    #[DataProvider('createPaths')]
    public function update_is_a_patch_and_delete_is_a_delete(string $section, string $path): void
    {
        // PATCH and not PUT on every section here: each update is partial in
        // fact — the platform builds its SET list from the fields present — and
        // PATCH is what this surface already uses everywhere. A library that
        // sent PUT would look correct and quietly imply full replacement.
        $client = $this->clientReturning($this->rowFor($section), $http);

        match ($section) {
            'groups' => $client->groups->update('abc', ['name' => 'new']),
            'consultants' => $client->consultants->update('abc', ['displayName' => 'new']),
            'projects' => $client->projects->update('abc', ['name' => 'new']),
            'projects2' => $client->projects2->update('abc', ['name' => 'new']),
            'awards' => $client->awards->update('abc', ['name' => 'new']),
        };
        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame("https://x.test/api/{$path}/abc", $http->lastCall()->url);

        match ($section) {
            'groups' => $client->groups->delete('abc'),
            'consultants' => $client->consultants->delete('abc'),
            'projects' => $client->projects->delete('abc'),
            'projects2' => $client->projects2->delete('abc'),
            'awards' => $client->awards->delete('abc'),
        };
        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame("https://x.test/api/{$path}/abc", $http->lastCall()->url);
    }

    // ─── Ids are caller input ────────────────────────────────────────────────

    #[Test]
    public function an_id_containing_a_slash_cannot_walk_onto_another_route(): void
    {
        // An id comes from the caller. Unencoded, "a/b" turns
        // DELETE sdk/awards/a/b into a path this SDK never meant to call —
        // which, on this backend, would not even 404: unmatched /api/* falls
        // into root-mounted routers and answers 400 "Tenant context required",
        // sending the integrator to debug credentials for a bad id.
        $client = $this->clientReturning([], $http);

        $client->awards->delete('a/b');
        self::assertSame('https://x.test/api/sdk/awards/a%2Fb', $http->lastCall()->url);

        $client->groups->update('a/b', ['name' => 'x']);
        self::assertSame('https://x.test/api/sdk/groups/a%2Fb', $http->lastCall()->url);

        $client->consultants->delete('a/b');
        self::assertSame('https://x.test/api/sdk/consultants/a%2Fb', $http->lastCall()->url);

        $client->projects2->update('a/b', ['name' => 'x']);
        self::assertSame('https://x.test/api/sdk/projects2/a%2Fb', $http->lastCall()->url);
    }

    // ─── Optional fields ─────────────────────────────────────────────────────

    #[Test]
    public function an_omitted_optional_field_is_absent_from_the_body_entirely(): void
    {
        // Absence must mean absence. A null tells the server a value was named
        // and could not be resolved; an absent key says the caller did not touch
        // it. On a group in particular, `nameArabic` absent means "default it to
        // the name" and `nameArabic: null` would be a NOT NULL violation.
        $client = $this->clientReturning($this->groupRow(), $http);
        $client->groups->create('فريق', 'وصف', false, 'https://cdn.test/i.png');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['name', 'description', 'isPrivate', 'iconUrl'], \array_keys($body));
    }

    #[Test]
    public function optional_fields_reach_the_wire_when_given(): void
    {
        $client = $this->clientReturning($this->consultantRow(), $http);
        $client->consultants->create('استشارة', 'https://book.test/x', [
            'bio' => 'وصف الخدمة',
            'status' => 'coming_soon',
            'typeIds' => ['t-1'],
        ]);

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('وصف الخدمة', $body['bio']);
        self::assertSame('coming_soon', $body['status']);
        self::assertSame(['t-1'], $body['typeIds']);
        // The required pair is still there, and still wins over anything with
        // the same name in $options.
        self::assertSame('استشارة', $body['displayName']);
        self::assertSame('https://book.test/x', $body['bookingUrl']);
    }

    #[Test]
    public function a_group_visibility_flag_is_sent_as_a_real_boolean(): void
    {
        // `true` and `"true"` are different values in JSON and the server
        // refuses the string — it accepts one only in the multipart web form,
        // which has no booleans to offer.
        $client = $this->clientReturning($this->groupRow(), $http);
        $client->groups->create('الإدارة', 'وصف', true, 'https://cdn.test/i.png');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($body['isPrivate']);
        self::assertIsBool($body['isPrivate']);
    }

    #[Test]
    public function a_project_is_created_with_name_even_though_it_is_read_back_as_title(): void
    {
        // The write contract accepts `name`; every response calls the same value
        // `title`. Sending `title` sets nothing and the server refuses the call
        // for a missing `name`, so this asymmetry is pinned rather than trusted.
        $client = $this->clientReturning($this->projectRow(), $http);
        $project = $client->projects->create('Bridge', 'A bridge');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Bridge', $body['name']);
        self::assertArrayNotHasKey('title', $body);
        self::assertSame('Bridge', $project->title);
    }

    // ─── Trimming ────────────────────────────────────────────────────────────

    #[Test]
    public function the_trimmed_values_are_what_get_sent(): void
    {
        // Validating a trimmed value and then transmitting the untrimmed one is
        // an easy, real bug: the local check passes and the server still
        // receives the whitespace.
        $client = $this->clientReturning($this->awardRow(), $http);
        $client->awards->create('  Gold  ', '  trophy  ', '  #093431  ');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Gold', $body['name']);
        self::assertSame('trophy', $body['icon']);
        self::assertSame('#093431', $body['color']);
    }

    // ─── Local refusals ──────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('localRefusals')]
    public function a_local_refusal_makes_no_request_at_all(string $case): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            match ($case) {
                'group without a name' => $client->groups->create('  ', 'وصف', false, 'https://c.test/i'),
                'group without an icon' => $client->groups->create('فريق', 'وصف', false, '   '),
                'consultant without a booking url' => $client->consultants->create('استشارة', ' '),
                'project without a description' => $client->projects->create('Bridge', ''),
                'projects2 without a name' => $client->projects2->create(' ', 'A bridge'),
                'award without a colour' => $client->awards->create('Gold', 'trophy', ''),
            };
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertSame(0, $e->status(), 'a local refusal never reached the network');
            self::assertCount(0, $http->calls, 'no rate-limit budget may be spent on an invalid draft');
        }
    }

    public static function localRefusals(): array
    {
        return [
            'group without a name' => ['group without a name'],
            'group without an icon' => ['group without an icon'],
            'consultant without a booking url' => ['consultant without a booking url'],
            'project without a description' => ['project without a description'],
            'projects2 without a name' => ['projects2 without a name'],
            'award without a colour' => ['award without a colour'],
        ];
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    private function rowFor(string $section): array
    {
        return match ($section) {
            'groups' => $this->groupRow(),
            'consultants' => $this->consultantRow(),
            'projects', 'projects2' => $this->projectRow(),
            'awards' => $this->awardRow(),
        };
    }

    private function groupRow(): array
    {
        return [
            'id' => 'grp_1',
            'name' => 'فريق',
            'isPrivate' => false,
            'memberCount' => 1,
            'memberLimit' => -1,
            'canChat' => true,
            'iconUrl' => 'https://cdn.test/i.png',
            'createdAt' => '2026-01-01T00:00:00.000Z',
        ];
    }

    private function consultantRow(): array
    {
        return [
            'id' => 'con_1',
            'displayName' => 'استشارة',
            'title' => null,
            'bookingUrl' => 'https://book.test/x',
            'status' => 'available',
            'isActive' => true,
            'price' => '250.00',
            'specialties' => [],
            'createdAt' => '2026-01-01T00:00:00.000Z',
        ];
    }

    private function projectRow(): array
    {
        return [
            'id' => 'prj_1',
            'title' => 'Bridge',
            'description' => 'A bridge',
            'createdAt' => '2026-01-01T00:00:00.000Z',
        ];
    }

    private function awardRow(): array
    {
        return [
            'id' => 'awd_1',
            'title' => 'Gold',
            'imageUrl' => 'trophy',
            'awardedAt' => null,
            'createdAt' => '2026-01-01T00:00:00.000Z',
        ];
    }
}
