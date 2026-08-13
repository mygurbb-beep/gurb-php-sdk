<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\CommunityRole;
use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Permission;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    private const KEY = ApiKeyTest::KEY;

    private function client(StubHttpClient $http): GurbClient
    {
        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    // ─── Roles ───────────────────────────────────────────────────────────────

    #[Test]
    public function setting_a_role_patches_the_role_subresource(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['memberId' => 'mem_1', 'role' => 'MODERATOR']]);

        $member = $this->client($http)->members->setRole('mem_1', CommunityRole::Moderator);

        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/members/mem_1/role', $http->lastCall()->url);
        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['role' => 'MODERATOR'], $body);
        self::assertSame('MODERATOR', $member->role);
    }

    #[Test]
    public function the_role_enum_has_no_platform_role_to_assign(): void
    {
        // The security claim, asserted rather than assumed. SUPER_ADMIN is
        // platform-level and no API call may grant it — leaving it out of the
        // enum means there is no way to ask for it, so this SDK cannot even
        // form the request the server would have to refuse.
        $names = \array_map(static fn (CommunityRole $r): string => $r->value, CommunityRole::cases());

        self::assertSame(['ADMIN', 'MODERATOR', 'MEMBER'], $names);
        self::assertNull(CommunityRole::tryFrom('SUPER_ADMIN'));
    }

    #[Test]
    public function a_role_filter_on_list_travels_as_its_backend_spelling(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['items' => []]]);

        $this->client($http)->members->list(role: CommunityRole::Admin);

        self::assertSame('https://x.test/api/sdk/members?role=ADMIN', $http->lastCall()->url);
    }

    // ─── Reading permissions ─────────────────────────────────────────────────

    #[Test]
    public function it_reads_role_effective_granted_and_revoked_separately(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'memberId' => 'mem_1',
            'userId' => 'usr_1',
            'role' => 'MODERATOR',
            'effective' => ['CREATE_POST', 'CREATE_EVENT', 'MODERATE_CONTENT'],
            'granted' => ['CREATE_EVENT'],
            'revoked' => ['DELETE_ANY_POST'],
        ]]);

        $permissions = $this->client($http)->members->getPermissions('mem_1');

        self::assertSame('https://x.test/api/sdk/members/mem_1/permissions', $http->lastCall()->url);
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame(CommunityRole::Moderator, $permissions->role());
        // Three lists, not one: "can they?" and "why?" are different questions,
        // and only the second tells you what to change.
        self::assertTrue($permissions->can(Permission::MODERATE_CONTENT));
        self::assertFalse($permissions->can(Permission::DELETE_ANY_POST));
        self::assertSame(['CREATE_EVENT'], $permissions->granted);
        self::assertSame(['DELETE_ANY_POST'], $permissions->revoked);
    }

    #[Test]
    public function an_unknown_role_from_a_newer_server_decodes_rather_than_throwing(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['role' => 'CURATOR', 'effective' => []]]);

        $permissions = $this->client($http)->members->getPermissions('mem_1');

        // Tolerant on the way in, strict on the way out. An additive backend
        // deploy must not become an outage for every host site — so the raw
        // string survives and only the enum accessor gives up.
        self::assertSame('CURATOR', $permissions->role);
        self::assertNull($permissions->role());
    }

    // ─── Updating permissions ────────────────────────────────────────────────

    #[Test]
    public function it_refuses_to_grant_and_revoke_the_same_permission_in_one_call(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        try {
            $this->client($http)->members->updatePermissions(
                'mem_1',
                grant: [Permission::CREATE_POST, Permission::CREATE_EVENT],
                revoke: [Permission::CREATE_POST],
            );
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('grant and revoke the same permission', $e->getMessage());
            // Names the offending permission, not just "conflict".
            self::assertStringContainsString('CREATE_POST', $e->getMessage());
            self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
        }

        // The point is that it never reached the network. There is no correct
        // guess at what was meant, so asking the server is not a fix — it just
        // moves the guess.
        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function it_refuses_an_empty_change_set(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        try {
            $this->client($http)->members->updatePermissions('mem_1');
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('at least one permission', $e->getMessage());
        }

        // An empty change set almost always means the caller built the lists
        // from a filter that matched nothing. Sending a no-op would hide it.
        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function it_sends_both_lists_in_one_request_so_the_member_is_never_half_updated(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['memberId' => 'mem_1', 'effective' => []]]);

        $this->client($http)->members->updatePermissions(
            'mem_1',
            grant: [Permission::CREATE_EVENT],
            revoke: [Permission::CREATE_POST],
        );

        // ONE call. Two calls could not be made atomic from out here: between
        // them the member holds a state nobody asked for, and if the second one
        // fails they stay in it.
        self::assertCount(1, $http->calls);
        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/members/mem_1/permissions', $http->lastCall()->url);
        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['grant' => ['CREATE_EVENT'], 'revoke' => ['CREATE_POST']], $body);
    }

    #[Test]
    public function an_empty_side_is_sent_as_a_json_array_not_an_object(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        $this->client($http)->members->updatePermissions('mem_1', grant: [Permission::CREATE_POST]);

        // `"revoke":[]` and not `"revoke":{}`. PHP's json_encode turns an empty
        // array into `[]`, but a *gapped* one into an object — which is why the
        // resource runs array_values() over both lists first.
        self::assertStringContainsString('"revoke":[]', (string) $http->lastCall()->body);
    }

    #[Test]
    public function a_list_left_gapped_by_array_filter_still_encodes_as_a_json_array(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        // The realistic way this breaks: a caller filters their own list and
        // hands us keys [1, 2]. Without array_values that becomes
        // {"1":"...","2":"..."} and the server rejects the whole call.
        $grant = \array_filter(
            [Permission::CREATE_POST, Permission::CREATE_EVENT, Permission::CREATE_BLOG],
            static fn (string $p): bool => $p !== Permission::CREATE_POST,
        );

        $this->client($http)->members->updatePermissions('mem_1', grant: $grant);

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['CREATE_EVENT', 'CREATE_BLOG'], $body['grant']);
    }

    #[Test]
    public function a_permission_the_sdk_has_never_heard_of_is_sent_anyway(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        $this->client($http)->members->updatePermissions('mem_1', grant: ['MANAGE_TIME_MACHINE']);

        // Permissions are strings, not an enum, precisely so that using one the
        // platform added last week does not require upgrading this package
        // first. The server owns the real answer and rejects what it dislikes.
        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['MANAGE_TIME_MACHINE'], $body['grant']);
    }

    #[Test]
    public function the_known_permission_list_is_reference_material_not_a_gate(): void
    {
        self::assertContains(Permission::CREATE_POST, Permission::KNOWN_PERMISSIONS);
        self::assertNotContains('MANAGE_TIME_MACHINE', Permission::KNOWN_PERMISSIONS);
        // Constants exist for autocomplete and typo-safety. If this list were
        // used to validate input, the test above would fail — which is the
        // whole argument for it not being an enum.
        self::assertCount(24, Permission::KNOWN_PERMISSIONS);
    }

    // ─── Removal ─────────────────────────────────────────────────────────────

    #[Test]
    public function removing_a_member_deletes_the_membership_and_returns_nothing(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => null]);

        $this->client($http)->members->remove('mem_1');

        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/members/mem_1', $http->lastCall()->url);
        // `data: null` unwraps to nothing to map, so the method is typed void
        // rather than handing back an empty array a caller might read as data.
        self::assertNull($http->lastCall()->body);
    }

    #[Test]
    public function a_member_id_that_could_break_out_of_the_path_is_escaped(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        $this->client($http)->members->getPermissions('mem/../../admin');

        self::assertSame(
            'https://x.test/api/sdk/members/mem%2F..%2F..%2Fadmin/permissions',
            $http->lastCall()->url,
        );
    }
}
