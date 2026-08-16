<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\CommentParent;
use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\LikeParent;
use Gurb\Model\Comment;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Comments and likes — the wire contract.
 *
 * WHY THESE ASSERTIONS AND NOT "does it work"
 *
 * Every method here is a PUBLISHED CONTRACT. Once a customer's integration calls
 * it, changing a path or a verb breaks their production silently, at a moment we
 * do not choose and cannot roll back for them. Pinning the verb and the URL in a
 * test means such a change fails here instead of there.
 *
 * These assertions are deliberately IDENTICAL to the ones in the TypeScript
 * SDK's interactions suite. The two libraries describe ONE contract, and the
 * only thing that stops them drifting apart is that both are pinned to the same
 * claims. If you change one file, change the other in the same commit.
 */
final class InteractionsTest extends TestCase
{
    private const KEY = 'gurb_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function clientReturning(array $data, ?StubHttpClient &$http = null): GurbClient
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => $data]);

        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    // ─── Comments: verb and path ─────────────────────────────────────────────

    #[Test]
    #[DataProvider('commentParents')]
    public function comments_are_addressed_under_their_parent(CommentParent $parent, string $segment): void
    {
        // The parent stays in the path even for edit and delete, where the
        // platform's own internal router flattens to /api/comments/:id. Gurb's
        // comment table has NO community column, so naming the parent is the
        // only way the server can prove the comment is inside the community this
        // key is pinned to.
        $client = $this->clientReturning(['items' => [$this->commentRow()], 'total' => 1], $http);
        $base = "https://x.test/api/sdk/{$segment}/p1/comments";

        $client->comments->list($parent, 'p1');
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame($base, $http->lastCall()->url);

        $client->comments->create($parent, 'p1', 'شكراً');
        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame($base, $http->lastCall()->url);

        $client->comments->update($parent, 'p1', 'c1', 'شكراً جزيلاً');
        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame("{$base}/c1", $http->lastCall()->url);

        $client->comments->delete($parent, 'p1', 'c1');
        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame("{$base}/c1", $http->lastCall()->url);
    }

    public static function commentParents(): array
    {
        return [
            // Four parents, matching the four content sections this SDK
            // publishes. Tasks are deliberately not among them: their comments
            // are a help-request conversation with a different cap and their own
            // notification rules.
            'tweets' => [CommentParent::Tweets, 'tweets'],
            'blogs' => [CommentParent::Blogs, 'blogs'],
            'albums' => [CommentParent::Albums, 'albums'],
            'events' => [CommentParent::Events, 'events'],
        ];
    }

    #[Test]
    public function a_comment_like_hangs_off_the_comment_and_its_parent(): void
    {
        $client = $this->clientReturning(['liked' => true, 'likeCount' => 3], $http);
        $path = 'https://x.test/api/sdk/blogs/p1/comments/c1/like';

        $state = $client->comments->likeState(CommentParent::Blogs, 'p1', 'c1');
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame($path, $http->lastCall()->url);
        self::assertTrue($state->liked);
        self::assertSame(3, $state->likeCount);

        $client->comments->like(CommentParent::Blogs, 'p1', 'c1');
        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame($path, $http->lastCall()->url);

        $client->comments->unlike(CommentParent::Blogs, 'p1', 'c1');
        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame($path, $http->lastCall()->url);
    }

    // ─── Likes: verb and path ────────────────────────────────────────────────

    #[Test]
    #[DataProvider('likeParents')]
    public function a_resource_like_is_one_path_and_three_verbs(LikeParent $parent, string $segment): void
    {
        // GET, POST and DELETE all answer the same shape, so a UI can render
        // from the response of the action it just took with no follow-up read.
        $client = $this->clientReturning(['liked' => false, 'likeCount' => 0], $http);
        $path = "https://x.test/api/sdk/{$segment}/p1/like";

        $client->likes->state($parent, 'p1');
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame($path, $http->lastCall()->url);

        $client->likes->like($parent, 'p1');
        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame($path, $http->lastCall()->url);

        $client->likes->unlike($parent, 'p1');
        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame($path, $http->lastCall()->url);
    }

    public static function likeParents(): array
    {
        // THREE, not four. There is no `LikeParent::Events`, because the
        // platform has no event like — its service answers "not yet
        // implemented" and publishes no route. Publishing a URL that cannot
        // work would be a contract promising something Gurb does not do.
        return [
            'tweets' => [LikeParent::Tweets, 'tweets'],
            'blogs' => [LikeParent::Blogs, 'blogs'],
            'albums' => [LikeParent::Albums, 'albums'],
        ];
    }

    #[Test]
    public function the_like_enum_has_no_event_case(): void
    {
        self::assertNull(LikeParent::tryFrom('events'), 'events cannot be liked; do not publish the URL');
        self::assertCount(3, LikeParent::cases());
    }

    // ─── Ids are caller input ────────────────────────────────────────────────

    #[Test]
    public function an_id_containing_a_slash_cannot_walk_onto_another_route(): void
    {
        // Both segments are caller text and both are encoded. Unencoded, "a/b"
        // turns this into a path this SDK never meant to call — which on this
        // backend answers 400 "Tenant context required" rather than 404, sending
        // the integrator to audit their credentials over a bad id.
        $client = $this->clientReturning([], $http);

        $client->comments->delete(CommentParent::Tweets, 'a/b', 'c/d');
        self::assertSame(
            'https://x.test/api/sdk/tweets/a%2Fb/comments/c%2Fd',
            $http->lastCall()->url,
        );

        $client->likes->like(LikeParent::Albums, 'a/b');
        self::assertSame('https://x.test/api/sdk/albums/a%2Fb/like', $http->lastCall()->url);
    }

    // ─── Bodies ──────────────────────────────────────────────────────────────

    #[Test]
    public function an_omitted_parent_comment_id_is_absent_from_the_body_entirely(): void
    {
        // Absence must mean absence. `parentCommentId: null` tells the server a
        // parent was named and could not be resolved — it refuses that outright
        // — while absence says this is a top-level comment.
        $client = $this->clientReturning($this->commentRow(), $http);
        $client->comments->create(CommentParent::Tweets, 'p1', 'شكراً');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['content'], \array_keys($body));
    }

    #[Test]
    public function a_reply_carries_the_comment_it_answers(): void
    {
        $client = $this->clientReturning($this->commentRow(), $http);
        $client->comments->create(CommentParent::Tweets, 'p1', 'أتفق', 'c1');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('c1', $body['parentCommentId']);
    }

    #[Test]
    public function the_trimmed_content_is_what_gets_sent(): void
    {
        // Validating a trimmed value and then transmitting the untrimmed one is
        // an easy, real bug: the local check passes and the server still
        // receives the whitespace — and content is capped at 800 characters.
        $client = $this->clientReturning($this->commentRow(), $http);

        $client->comments->create(CommentParent::Tweets, 'p1', '  شكراً  ');
        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('شكراً', $body['content']);

        $client->comments->update(CommentParent::Tweets, 'p1', 'c1', '  معدّل  ');
        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('معدّل', $body['content']);
    }

    #[Test]
    public function a_like_sends_no_body_at_all(): void
    {
        // Nothing to say: the actor is the key and the target is the path.
        $client = $this->clientReturning(['liked' => true, 'likeCount' => 1], $http);
        $client->likes->like(LikeParent::Tweets, 'p1');

        self::assertNull($http->lastCall()->body);
    }

    // ─── Local refusals ──────────────────────────────────────────────────────

    #[Test]
    public function an_empty_comment_is_refused_before_any_request(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->comments->create(CommentParent::Tweets, 'p1', '   ');
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('needs content', $e->getMessage());
            self::assertCount(0, $http->calls, 'comments are rate limited; an empty draft may not spend budget');
        }
    }

    #[Test]
    public function an_empty_edit_is_refused_before_any_request(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->comments->update(CommentParent::Tweets, 'p1', 'c1', '');
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertSame(0, $e->status());
            self::assertCount(0, $http->calls);
        }
    }

    #[Test]
    public function a_blank_parent_comment_id_is_refused_rather_than_posted_at_top_level(): void
    {
        // A blank id almost always means a variable that failed to resolve.
        // Silently turning someone's reply into a new thread is worse than a
        // refusal, and it is invisible until a human reads the thread.
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->comments->create(CommentParent::Tweets, 'p1', 'أتفق', '  ');
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('parentCommentId', $e->getMessage());
            self::assertCount(0, $http->calls);
        }
    }

    // ─── Decoding ────────────────────────────────────────────────────────────

    #[Test]
    public function a_comment_publishes_both_ids_of_its_author(): void
    {
        // `userId` is the platform account; `memberId` is that account's
        // membership row in THIS community, and it is what the comment row
        // stores. Comparing a stored userId against it finds nothing — the exact
        // confusion that once made members' own likes invisible in production.
        $client = $this->clientReturning(['items' => [$this->commentRow()], 'total' => 1], $http);
        $comment = $client->comments->list(CommentParent::Tweets, 'p1')->items[0];

        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame('usr_1', $comment->author->userId);
        self::assertSame('mem_1', $comment->author->memberId);
        self::assertSame('عضو', $comment->author->displayName);
        self::assertFalse($comment->author->isFormerMember);
    }

    #[Test]
    public function replies_decode_recursively(): void
    {
        // Pagination counts TOP-LEVEL comments, so a page of 20 may hold far
        // more than 20 comments in total.
        $row = $this->commentRow();
        $row['replies'] = [$this->commentRow()];

        $client = $this->clientReturning(['items' => [$row], 'total' => 1], $http);
        $comment = $client->comments->list(CommentParent::Tweets, 'p1')->items[0];

        self::assertCount(1, $comment->replies);
        self::assertInstanceOf(Comment::class, $comment->replies[0]);
    }

    private function commentRow(): array
    {
        return [
            'id' => 'c1',
            'content' => 'شكراً',
            'author' => [
                'userId' => 'usr_1',
                'memberId' => 'mem_1',
                'displayName' => 'عضو',
                'avatarUrl' => null,
                'isFormerMember' => false,
            ],
            'parentCommentId' => null,
            'attachmentUrls' => [],
            'likeCount' => 0,
            'likedByMe' => false,
            'isEdited' => false,
            'createdAt' => '2026-01-01T00:00:00.000Z',
            'replies' => [],
        ];
    }
}
