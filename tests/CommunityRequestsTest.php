<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\CommunityRequestStatus;
use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Input\CreateCommunityInput;
use Gurb\Model\Community;
use Gurb\Model\CommunityRequest;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A community key asks; it does not create. These tests pin that down from the
 * client side — the type it gets back is the proof.
 */
final class CommunityRequestsTest extends TestCase
{
    private const KEY = ApiKeyTest::KEY;

    private function client(StubHttpClient $http): GurbClient
    {
        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    #[Test]
    public function requesting_a_community_returns_a_pending_request_and_not_a_community(): void
    {
        $http = StubHttpClient::json(201, ['data' => [
            'id' => 'creq_1',
            'name' => 'نادي القراءة',
            'slug' => 'book-club',
            'status' => 'PENDING',
            'requestedByUserId' => 'usr_1',
            'decidedByUserId' => null,
            'communityId' => null,
            'createdAt' => '2026-08-13T09:00:00Z',
        ]]);

        $request = $this->client($http)->requestCommunityCreation(
            new CreateCommunityInput(name: 'نادي القراءة', slug: 'book-club'),
        );

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/community-requests', $http->lastCall()->url);

        // The whole approval model in two assertions: what came back is a piece
        // of paper, not a community, and it has no community id yet.
        self::assertInstanceOf(CommunityRequest::class, $request);
        self::assertNotInstanceOf(Community::class, $request);
        self::assertTrue($request->isPending());
        self::assertNull($request->communityId);
    }

    #[Test]
    public function the_resource_method_and_the_client_shortcut_send_the_same_request(): void
    {
        $input = new CreateCommunityInput(name: 'نادي', slug: 'club');

        $viaClient = StubHttpClient::json(201, ['data' => []]);
        $this->client($viaClient)->requestCommunityCreation($input);

        $viaResource = StubHttpClient::json(201, ['data' => []]);
        $this->client($viaResource)->community->requestCreation($input);

        // One is a forwarder for the other. If they ever diverge, one of the two
        // names in the docs is a lie.
        self::assertSame($viaClient->lastCall()->url, $viaResource->lastCall()->url);
        self::assertSame($viaClient->lastCall()->body, $viaResource->lastCall()->body);
    }

    #[Test]
    public function a_taken_slug_is_an_ordinary_validation_error_to_handle(): void
    {
        $http = StubHttpClient::json(400, [
            'success' => false,
            'error' => 'VALIDATION_ERROR',
            'message' => 'The slug "demo" is already taken',
        ]);

        try {
            $this->client($http)->requestCommunityCreation(new CreateCommunityInput('Demo', 'demo'));
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            // Slugs are unique platform-wide, so this is a normal outcome that
            // deserves a form error, not a 500 page.
            self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
            self::assertSame(400, $e->status());
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function it_lists_its_own_requests_filtered_by_status(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'items' => [['id' => 'creq_1', 'status' => 'PENDING']],
            'page' => 1,
            'limit' => 20,
            'total' => 1,
        ]]);

        $page = $this->client($http)->communityRequests->list(CommunityRequestStatus::Pending);

        self::assertSame('https://x.test/api/sdk/community-requests?status=PENDING', $http->lastCall()->url);
        self::assertCount(1, $page);
        self::assertTrue($page->items[0]->isPending());
    }

    #[Test]
    public function a_rejected_request_carries_the_reason_the_requester_is_shown(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'id' => 'creq_1',
            'status' => 'REJECTED',
            'rejectionReason' => 'The slug is too generic',
            'decidedByUserId' => 'usr_superadmin',
            'decidedAt' => '2026-08-13T12:00:00Z',
        ]]);

        $request = $this->client($http)->communityRequests->get('creq_1');

        self::assertSame('https://x.test/api/sdk/community-requests/creq_1', $http->lastCall()->url);
        self::assertTrue($request->isRejected());
        self::assertFalse($request->isPending());
        self::assertSame('The slug is too generic', $request->rejectionReason);
    }

    #[Test]
    public function an_approved_request_names_the_community_it_became(): void
    {
        $http = StubHttpClient::json(200, ['data' => [
            'id' => 'creq_1',
            'status' => 'APPROVED',
            'communityId' => 'cmt_new',
        ]]);

        $request = $this->client($http)->communityRequests->get('creq_1');

        self::assertTrue($request->isApproved());
        self::assertSame(CommunityRequestStatus::Approved, $request->status());
        // Null until approved, so this is the field you poll for.
        self::assertSame('cmt_new', $request->communityId);
    }

    #[Test]
    public function a_status_this_sdk_predates_decodes_rather_than_throwing(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['id' => 'creq_1', 'status' => 'WITHDRAWN']]);

        $request = $this->client($http)->communityRequests->get('creq_1');

        // Tolerant decoding: an additive backend deploy must not take every host
        // site down. The raw string survives; only the enum accessor gives up.
        self::assertSame('WITHDRAWN', $request->status);
        self::assertNull($request->status());
        self::assertFalse($request->isPending());
    }

    #[Test]
    public function a_missing_status_reads_as_pending_rather_than_empty(): void
    {
        $http = StubHttpClient::json(200, ['data' => ['id' => 'creq_1']]);

        $request = $this->client($http)->communityRequests->get('creq_1');

        // An unreadable status is far more likely to be a request still waiting
        // than one already acted on, and "not decided yet" is the safe thing to
        // show a user.
        self::assertTrue($request->isPending());
    }

    #[Test]
    public function the_community_client_has_no_way_to_approve_anything(): void
    {
        $client = $this->client(StubHttpClient::json(200, ['data' => []]));

        // Asserted rather than assumed. Approval needs a super-admin key and
        // lives on GurbAdminClient; if these methods ever appeared here, the
        // two-credential split would be decorative.
        self::assertFalse(\method_exists($client->communityRequests, 'approve'));
        self::assertFalse(\method_exists($client->communityRequests, 'reject'));
        self::assertFalse(\method_exists($client->community, 'create'));
    }
}
