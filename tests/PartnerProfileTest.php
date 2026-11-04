<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbClient;
use Gurb\Input\BulkMemberInput;
use Gurb\Input\MemberProfileInput;
use Gurb\Input\MemberSocialLinks;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Partner profile fields (1.11.0). The wire must match @gurb/server's byte for
 * byte: a null argument is OMITTED (untouched on the server), '' is sent as ''
 * (clear), and a row without a profile carries no `profile` key at all.
 */
final class PartnerProfileTest extends TestCase
{
    private const KEY = ApiKeyTest::KEY;

    private function profile(): MemberProfileInput
    {
        return new MemberProfileInput(
            bio: 'Founder of Acme',
            jobTitle: 'CEO',
            company: 'Acme',
            industry: 'Retail',
            whatsapp: '',
            social: new MemberSocialLinks(linkedin: 'https://www.linkedin.com/in/ali', x: 'https://x.com/ali'),
        );
    }

    /** @var array<string, mixed> */
    private const WIRE = [
        'bio' => 'Founder of Acme',
        'jobTitle' => 'CEO',
        'company' => 'Acme',
        'industry' => 'Retail',
        'whatsapp' => '',
        'social' => ['linkedin' => 'https://www.linkedin.com/in/ali', 'x' => 'https://x.com/ali'],
    ];

    #[Test]
    public function create_embed_session_sends_the_profile(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['token' => 'et_abc', 'expiresAt' => '2026-08-11T00:02:00Z', 'userId' => 'u1']]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $client->createEmbedSession('riyadah_1', 'Ali', profile: $this->profile());

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['externalUserId' => 'riyadah_1', 'displayName' => 'Ali', 'profile' => self::WIRE], $body);
        // Untouched fields are absent, not null — null would CLEAR them.
        self::assertArrayNotHasKey('city', $body['profile']);
        self::assertArrayNotHasKey('phone', $body['profile']);
    }

    #[Test]
    public function bulk_upsert_sends_each_rows_profile_and_none_where_none_was_given(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['created' => [], 'updated' => [], 'failed' => [], 'total' => 2]]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        $client->members->bulkUpsert([
            new BulkMemberInput('riyadah_1', 'Ali', profile: $this->profile()),
            new BulkMemberInput('riyadah_2', 'Sara'),
        ]);

        $call = $http->lastCall();
        self::assertSame('https://x.test/api/sdk/members/bulk', $call->url);
        $body = \json_decode((string) $call->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'members' => [
                ['externalUserId' => 'riyadah_1', 'displayName' => 'Ali', 'profile' => self::WIRE],
                ['externalUserId' => 'riyadah_2', 'displayName' => 'Sara'],
            ],
        ], $body);
    }
}
