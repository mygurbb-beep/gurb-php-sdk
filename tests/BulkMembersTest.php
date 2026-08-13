<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\CommunityRole;
use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Input\BulkMemberInput;
use Gurb\Resource\MembersResource;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BulkMembersTest extends TestCase
{
    private const KEY = ApiKeyTest::KEY;

    private function client(StubHttpClient $http): GurbClient
    {
        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    /** @return list<BulkMemberInput> */
    private function rows(int $count): array
    {
        return \array_map(
            static fn (int $i): BulkMemberInput => new BulkMemberInput("u{$i}", "User {$i}"),
            \range(0, $count - 1),
        );
    }

    // ─── Local guards ────────────────────────────────────────────────────────

    #[Test]
    public function it_rejects_an_empty_list(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        try {
            $this->client($http)->members->bulkUpsert([]);
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('at least one member', $e->getMessage());
        }

        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function it_rejects_a_list_over_the_limit_without_sending_it(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        try {
            $this->client($http)->members->bulkUpsert($this->rows(501));
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('at most 500', $e->getMessage());
            // Tells you what you sent and what to do about it, not just "too big".
            self::assertStringContainsString('you sent 501', $e->getMessage());
            self::assertStringContainsString('Chunk the list', $e->getMessage());
        }

        // Sending it would likely die at a proxy timeout with no way to know
        // which half landed — strictly worse than a clean local failure.
        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function it_accepts_exactly_the_limit(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['created' => [], 'updated' => [], 'failed' => [], 'total' => 500]]);

        $result = $this->client($http)->members->bulkUpsert($this->rows(MembersResource::BULK_MEMBER_LIMIT));

        // The boundary, not just "somewhere under it". An off-by-one here would
        // make the documented limit a lie.
        self::assertSame(500, $result->total);
        self::assertCount(1, $http->calls);
    }

    #[Test]
    public function it_names_a_duplicate_id_rather_than_letting_the_batch_race_itself(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        try {
            $this->client($http)->members->bulkUpsert([
                new BulkMemberInput('dup', 'A'),
                new BulkMemberInput('other', 'B'),
                new BulkMemberInput('dup', 'C'),
            ]);
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            // "Duplicate entry" in a batch of 500 is a message you cannot act
            // on. The id is what lets you find the row.
            self::assertSame('Duplicate externalUserId in the same batch: dup', $e->getMessage());
        }

        self::assertCount(0, $http->calls);
    }

    #[Test]
    public function every_local_guard_fails_in_exactly_the_same_shape(): void
    {
        $client = $this->client(StubHttpClient::json(200, ['data' => []]));

        $guards = [
            'empty list' => static fn () => $client->members->bulkUpsert([]),
            'over limit' => fn () => $client->members->bulkUpsert($this->rows(501)),
            'duplicate id' => static fn () => $client->members->bulkUpsert([
                new BulkMemberInput('dup', 'A'),
                new BulkMemberInput('dup', 'B'),
            ]),
            'conflicting permissions' => static fn () => $client->members->updatePermissions(
                'mem_1',
                grant: ['CREATE_POST'],
                revoke: ['CREATE_POST'],
            ),
            'empty change set' => static fn () => $client->members->updatePermissions('mem_1'),
        ];

        foreach ($guards as $label => $guard) {
            try {
                $guard();
                self::fail("{$label} should have thrown.");
            } catch (GurbApiException $e) {
                // ONE KIND OF FAILURE. The TypeScript SDK originally threw some
                // of these synchronously out of methods declared to return a
                // Promise, so callers needed try/catch for one guard and
                // .catch() for another — for errors that are the same error to
                // the person calling. PHP cannot reproduce that split, but the
                // lesson is worth a test: same code, same status, every time.
                self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code(), $label);
                self::assertSame(0, $e->status(), "{$label}: nothing was sent, so no HTTP status");
                self::assertFalse($e->isRetryable(), "{$label}: a bad argument will not fix itself");
            }
        }
    }

    // ─── The request ─────────────────────────────────────────────────────────

    #[Test]
    public function it_posts_the_rows_under_a_members_key_matched_on_external_user_id(): void
    {
        $http = StubHttpClient::json(200, ['data' => []]);

        $this->client($http)->members->bulkUpsert([
            new BulkMemberInput('shop-1', 'Sara', 'sara@example.com', CommunityRole::Moderator),
            new BulkMemberInput('shop-2', 'Omar'),
        ]);

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/members/bulk', $http->lastCall()->url);

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'members' => [
                ['externalUserId' => 'shop-1', 'displayName' => 'Sara', 'email' => 'sara@example.com', 'role' => 'MODERATOR'],
                ['externalUserId' => 'shop-2', 'displayName' => 'Omar'],
            ],
        ], $body);
        // Omitted, not null — the second row has no email and no role, and the
        // server's "absent means default" branch is what we want to hit.
        self::assertArrayNotHasKey('email', $body['members'][1]);
        self::assertArrayNotHasKey('role', $body['members'][1]);
    }

    #[Test]
    public function resending_the_same_batch_is_a_sync_not_a_second_import(): void
    {
        // Matched on externalUserId server-side, which is what makes this safe
        // to run nightly AND safe to retry after a timeout — you cannot tell a
        // timeout that landed from one that did not.
        $http = StubHttpClient::json(200, ['data' => [
            'created' => [],
            'updated' => [['memberId' => 'mem_1', 'userId' => 'usr_1', 'displayName' => 'Sara']],
            'failed' => [],
            'total' => 1,
        ]]);

        $result = $this->client($http)->members->bulkUpsert([new BulkMemberInput('shop-1', 'Sara')]);

        self::assertCount(0, $result->created);
        self::assertCount(1, $result->updated);
        self::assertSame('mem_1', $result->updated[0]->memberId);
    }

    // ─── Partial failure ─────────────────────────────────────────────────────

    #[Test]
    public function individual_row_failures_do_not_throw_and_do_not_discard_the_batch(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'created' => [
                ['memberId' => 'mem_1', 'userId' => 'usr_1', 'displayName' => 'Sara'],
                ['memberId' => 'mem_2', 'userId' => 'usr_2', 'displayName' => 'Omar'],
            ],
            'updated' => [],
            'failed' => [[
                'index' => 1,
                'externalUserId' => 'shop-2',
                'code' => 'VALIDATION_ERROR',
                'message' => 'Invalid email: not-an-email',
            ]],
            'total' => 3,
        ]]);

        // No try/catch here on purpose: one bad email in a list of 500 must not
        // discard the other 499, so this is a return value and not a throw.
        $result = $this->client($http)->members->bulkUpsert([
            new BulkMemberInput('shop-1', 'Sara'),
            new BulkMemberInput('shop-2', 'Bad', 'not-an-email'),
            new BulkMemberInput('shop-3', 'Omar'),
        ]);

        self::assertTrue($result->hasFailures());
        self::assertSame(2, $result->successCount());
        self::assertSame(3, $result->total);
        self::assertSame(GurbErrorCode::VALIDATION_ERROR, $result->failed[0]->code);
    }

    #[Test]
    public function a_failure_carries_the_index_of_the_row_you_sent(): void
    {
        $rows = [
            new BulkMemberInput('shop-1', 'Sara'),
            new BulkMemberInput('shop-2', 'Bad', 'not-an-email'),
        ];
        $http = StubHttpClient::json(200, ['data' => [
            'created' => [],
            'updated' => [],
            'failed' => [['index' => 1, 'externalUserId' => 'shop-2', 'code' => 'VALIDATION_ERROR', 'message' => 'Invalid email']],
            'total' => 2,
        ]]);

        $result = $this->client($http)->members->bulkUpsert($rows);

        // The index is what maps a failure back to its input. Without it you
        // have an externalUserId and a spreadsheet, which is not enough.
        $failure = $result->failed[0];
        self::assertSame(1, $failure->index);
        self::assertSame($rows[$failure->index]->externalUserId, $failure->externalUserId);
        self::assertSame('Bad', $rows[$failure->index]->displayName);
    }

    #[Test]
    public function a_clean_batch_reports_no_failures(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'created' => [['memberId' => 'mem_1']],
            'updated' => [],
            'failed' => [],
            'total' => 1,
        ]]);

        $result = $this->client($http)->members->bulkUpsert([new BulkMemberInput('shop-1', 'Sara')]);

        // An empty `failed` is the ONLY proof everything landed. A 200 alone
        // means "the batch was processed", which is a weaker claim.
        self::assertFalse($result->hasFailures());
        self::assertSame(1, $result->successCount());
    }

    #[Test]
    public function a_total_the_server_omitted_falls_back_to_the_sum_not_to_zero(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'created' => [['memberId' => 'mem_1'], ['memberId' => 'mem_2']],
            'updated' => [],
            'failed' => [['index' => 2, 'externalUserId' => 'x', 'code' => 'VALIDATION_ERROR', 'message' => 'bad']],
        ]]);

        $result = $this->client($http)->members->bulkUpsert($this->rows(3));

        // A zero total printed next to two created members would read as
        // "nothing happened" in a log at 3am.
        self::assertSame(3, $result->total);
    }

    #[Test]
    public function a_whole_batch_failure_is_still_an_exception(): void
    {
        // Partial failures are data; a rejected REQUEST is still an error. The
        // distinction is the status code, not the endpoint.
        $http = StubHttpClient::json(400, [
            'success' => false,
            'error' => 'VALIDATION_ERROR',
            'message' => 'members must be a non-empty array',
        ]);

        try {
            $this->client($http)->members->bulkUpsert([new BulkMemberInput('shop-1', 'Sara')]);
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
            // 400, not 0 — this one DID reach a server, which is exactly the
            // distinction status 0 exists to draw.
            self::assertSame(400, $e->status());
        }
    }
}
