<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbAdminClient;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `$admin->me()` — who the key acts for.
 *
 * The bug this endpoint exists to make visible: a platform key can only be
 * MINTED by a super admin, so before keys could name a subject, every community
 * created through the SDK was founded by the platform admin. A customer handed a
 * key to build their own community founded nothing.
 *
 * These tests therefore care most about the CAUTIOUS DEFAULTS. Every ambiguous
 * or missing field must resolve toward "this key is not set up for you" and
 * "this phone number is not real", because the opposite defaults fail silently
 * and in the expensive direction.
 */
final class IdentityTest extends TestCase
{
    private const KEY = 'gurb_sa_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function adminReturning(array $data, ?StubHttpClient &$http = null): GurbAdminClient
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => $data]);

        return new GurbAdminClient(self::KEY, 'https://x.test', $http);
    }

    private function person(array $over = []): array
    {
        return \array_merge([
            'userId' => 'u-1',
            'email' => 'owner@corp.com',
            'displayName' => 'أحمد علي',
            'phone' => '0500000001',
            'phoneIsPlaceholder' => false,
        ], $over);
    }

    // ─── The wire ────────────────────────────────────────────────────────────

    #[Test]
    public function me_is_a_get_to_the_platform_identity_path(): void
    {
        $admin = $this->adminReturning([
            'issuedBy' => $this->person(),
            'subject' => null,
            'actsForIssuer' => true,
        ], $http);

        $admin->me();

        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/admin/sdk/me', $http->lastCall()->url);
    }

    // ─── The question it exists to answer ────────────────────────────────────

    #[Test]
    public function a_key_with_a_subject_reports_that_subject_as_the_owner(): void
    {
        $admin = $this->adminReturning([
            'issuedBy' => $this->person(['userId' => 'admin-1', 'email' => 'root@gurb.com']),
            'subject' => $this->person(['userId' => 'cust-9', 'email' => 'customer@corp.com']),
            'actsForIssuer' => false,
        ]);

        $me = $admin->me();

        self::assertFalse($me->actsForIssuer);
        self::assertSame('cust-9', $me->effectiveOwner()->userId);
        self::assertSame('customer@corp.com', $me->effectiveOwner()->email);
    }

    #[Test]
    public function a_key_with_NO_subject_falls_back_to_the_issuer_and_says_so(): void
    {
        // This is the shape that means "anything you create will belong to the
        // platform admin". It must be loudly detectable, not inferred.
        $admin = $this->adminReturning([
            'issuedBy' => $this->person(['userId' => 'admin-1']),
            'subject' => null,
            'actsForIssuer' => true,
        ]);

        $me = $admin->me();

        self::assertTrue($me->actsForIssuer);
        self::assertNull($me->subject);
        self::assertSame('admin-1', $me->effectiveOwner()->userId);
    }

    #[Test]
    public function an_older_server_that_omits_the_flag_is_treated_as_having_NO_subject(): void
    {
        // A server predating subjects genuinely acts for its issuer. Defaulting
        // the other way would tell an integrator their key was set up for them
        // on exactly the deployments where it cannot have been.
        $admin = $this->adminReturning(['issuedBy' => $this->person()]);

        self::assertTrue($admin->me()->actsForIssuer);
    }

    // ─── The placeholder phone ───────────────────────────────────────────────

    #[Test]
    public function a_missing_number_is_a_placeholder_and_realPhone_admits_it(): void
    {
        $admin = $this->adminReturning([
            'issuedBy' => $this->person(['phone' => '00000000000', 'phoneIsPlaceholder' => true]),
            'subject' => null,
            'actsForIssuer' => true,
        ]);

        $owner = $admin->me()->effectiveOwner();

        // Never null — a card has a field of the right shape to lay out.
        self::assertSame('00000000000', $owner->phone);
        // But the truth is available without string-comparing against a constant
        // that could change.
        self::assertNull($owner->realPhone());
    }

    #[Test]
    public function a_real_number_survives_intact(): void
    {
        $admin = $this->adminReturning([
            'issuedBy' => $this->person(['phone' => '0512345678', 'phoneIsPlaceholder' => false]),
            'subject' => null,
            'actsForIssuer' => true,
        ]);

        self::assertSame('0512345678', $admin->me()->effectiveOwner()->realPhone());
    }

    #[Test]
    public function an_absent_placeholder_flag_assumes_the_number_is_NOT_real(): void
    {
        // Assuming a number is real when the server never said so is the
        // direction that ends with somebody dialling a run of zeros.
        $admin = $this->adminReturning([
            'issuedBy' => ['userId' => 'u-1', 'email' => 'a@b.com', 'phone' => '00000000000'],
        ]);

        self::assertNull($admin->me()->effectiveOwner()->realPhone());
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    #[Test]
    public function canWrite_reflects_the_keys_scopes(): void
    {
        $rw = $this->adminReturning([
            'issuedBy' => $this->person(),
            'key' => ['id' => 'k1', 'name' => 'ci', 'scopes' => ['read', 'write']],
        ]);
        self::assertTrue($rw->me()->canWrite());

        $ro = $this->adminReturning([
            'issuedBy' => $this->person(),
            'key' => ['id' => 'k1', 'name' => 'ci', 'scopes' => ['read']],
        ]);
        self::assertFalse($ro->me()->canWrite());
    }

    #[Test]
    public function a_malformed_scopes_payload_does_not_grant_write(): void
    {
        // Anything that is not a list of strings resolves to no scopes, and no
        // scopes means no write. Failing open here would tell an integrator they
        // could publish, and let them discover otherwise mid-import.
        $admin = $this->adminReturning([
            'issuedBy' => $this->person(),
            'key' => ['id' => 'k1', 'scopes' => 'write'],
        ]);

        self::assertFalse($admin->me()->canWrite());
        self::assertSame([], $admin->me()->scopes);
    }
}
