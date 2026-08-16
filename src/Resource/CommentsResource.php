<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\CommentParent;
use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Comment;
use Gurb\Model\LikeState;
use Gurb\Model\Paginated;

/**
 * Comments, and the likes ON comments.
 *
 * COMMENTING NEEDS NO PERMISSION, AND THAT IS THE OPPOSITE OF POSTING — worth
 * saying out loud because every other write class in this SDK opens by naming a
 * permission. Answering is what an ordinary member does, so MEMBERSHIP is the
 * whole gate: Gurb's own comment routes carry no `requireAnyPermission` and no
 * `requirePermission` anywhere, and this SDK carries none either. A key whose
 * owner was granted nothing — which is every plain member, since `MEMBER` is an
 * empty permission set in Gurb — cannot publish a post and CAN comment on one.
 *
 * Adding a permission here, even a "safe" one, would mean a member who can
 * comment in the web app cannot comment through an integration. That is the
 * precise divergence this SDK exists not to introduce.
 *
 * WHAT DOES REFUSE YOU, then:
 *
 *   - the parent's FEATURE GATE, which differs per parent. Blogs, albums and
 *     events sit behind their module's plan flag, so a community without it
 *     gets 403 FEATURE_NOT_AVAILABLE here — on the LIST as well as the write.
 *     Tweets carry no gate. An entitlement is not a permission and no grant
 *     fixes one.
 *   - the parent's own `allowComments` switch. Its author may turn commenting
 *     off, and that is its own refusal, not a permission problem.
 *   - TIME, on edit and delete. See those methods.
 *
 * THE PARENT IS ALWAYS IN THE PATH, even for editing a comment whose id is
 * already unique. Gurb's comment table is keyed by the pair (resource type,
 * resource id) and has NO community column, so naming the parent is the only
 * way the server can check the comment is inside the community your key is
 * pinned to. Passing the wrong parent for a real comment id is a 404, not a
 * silent cross-community edit.
 */
final class CommentsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * One page of a thread.
     *
     * PAGINATION COUNTS TOP-LEVEL COMMENTS ONLY. Replies travel nested inside
     * their parent (`Comment::$replies`, to unlimited depth), so a page of 20
     * may contain far more than 20 comments in total.
     *
     * @return Paginated<Comment>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when the parent's module is
     *                          not on this community's plan.
     */
    public function list(
        CommentParent $parent,
        string $parentId,
        ?int $page = null,
        ?int $limit = null,
    ): Paginated {
        return Paginated::fromArray(
            $this->requester->request(
                'GET',
                $this->base($parent, $parentId),
                ['page' => $page, 'limit' => $limit],
            ),
            Comment::fromArray(...),
        );
    }

    /**
     * Post a comment, or a reply.
     *
     * ONE METHOD, NOT TWO. Gurb's own system splits them because a multipart
     * form cannot nest; this surface is JSON, where the parent comment is just a
     * field, and two endpoints differing only in where one id travels would be
     * two things to learn and one more place for them to disagree.
     *
     * No permission — membership is the gate. See the class note.
     *
     * `$content` is capped at 800 characters upstream and is trimmed here; the
     * trimmed value is what gets sent.
     *
     * `$parentCommentId` must name a comment on THIS parent, or you get a 404.
     * Omit it entirely for a top-level comment — passing an empty string is
     * refused locally rather than quietly posted at top level, because a blank
     * id almost always means a variable that failed to resolve, and silently
     * turning someone's reply into a new thread is worse than a 400.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          for empty content or a blank parentCommentId.
     */
    public function create(
        CommentParent $parent,
        string $parentId,
        string $content,
        ?string $parentCommentId = null,
    ): Comment {
        $trimmed = \trim($content);
        if ($trimmed === '') {
            // Caught locally so an empty draft costs no round trip and no
            // rate-limit budget — and comments ARE rate limited, at 20 with a
            // one-per-second refill.
            throw GurbApiException::localValidation('A comment needs content.');
        }

        // The trimmed value is what gets SENT, not merely what got validated.
        $body = ['content' => $trimmed];

        if ($parentCommentId !== null) {
            $trimmedParent = \trim($parentCommentId);
            if ($trimmedParent === '') {
                throw GurbApiException::localValidation(
                    'parentCommentId must name a comment; omit it entirely for a top-level comment.',
                );
            }
            $body['parentCommentId'] = $trimmedParent;
        }
        // Omitted rather than sent as null when the caller passed nothing:
        // absence must mean absence, and the server reads an explicit null as a
        // caller who tried to name a parent and failed.

        return Comment::fromArray(
            $this->requester->request('POST', $this->base($parent, $parentId), body: $body),
        );
    }

    /**
     * Edit a comment.
     *
     * AUTHOR ONLY, WITHIN 15 MINUTES, AND NO PERMISSION OVERRIDES IT. There is
     * no moderator escape hatch here on either surface — nobody edits another
     * member's words; a moderator who needs a comment gone deletes it. So this
     * succeeds only for comments the KEY'S OWNER wrote, and only just after they
     * wrote them.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          for empty content.
     */
    public function update(
        CommentParent $parent,
        string $parentId,
        string $commentId,
        string $content,
    ): Comment {
        $trimmed = \trim($content);
        if ($trimmed === '') {
            throw GurbApiException::localValidation('A comment needs content.');
        }

        return Comment::fromArray($this->requester->request(
            'PATCH',
            $this->base($parent, $parentId) . '/' . \rawurlencode($commentId),
            // The trimmed value is what gets SENT.
            body: ['content' => $trimmed],
        ));
    }

    /**
     * Delete a comment.
     *
     * A WIDER RULE THAN EDIT, and the asymmetry is real: the author may delete
     * within 30 minutes (not 15), and a community ADMIN or MODERATOR may delete
     * at any time with no window at all. That moderator power is a role, not a
     * grantable permission — it comes from the key owner's role in the
     * community, which is why no permission name appears here.
     *
     * THE DELETE CASCADES TO REPLIES. Removing a comment removes the thread
     * hanging off it, and exactly one deletion is announced — the one you asked
     * for.
     *
     * @throws GurbApiException
     */
    public function delete(CommentParent $parent, string $parentId, string $commentId): void
    {
        $this->requester->request(
            'DELETE',
            $this->base($parent, $parentId) . '/' . \rawurlencode($commentId),
        );
    }

    /**
     * Does the KEY'S OWNER like this comment, and how many people do?
     *
     * No permission. `LikeState::$liked` is about the human the key was minted
     * for and nobody else — a server key cannot express "did this visitor like
     * it", which is what embed sessions are for.
     *
     * @throws GurbApiException
     */
    public function likeState(CommentParent $parent, string $parentId, string $commentId): LikeState
    {
        return LikeState::fromArray(
            $this->requester->request('GET', $this->likePath($parent, $parentId, $commentId)),
        );
    }

    /**
     * Like a comment, as the key's owner. Idempotent — liking twice leaves one
     * like. No permission; rate limited.
     *
     * @throws GurbApiException
     */
    public function like(CommentParent $parent, string $parentId, string $commentId): LikeState
    {
        return LikeState::fromArray(
            $this->requester->request('POST', $this->likePath($parent, $parentId, $commentId)),
        );
    }

    /**
     * Remove the key owner's like. Answers the same shape as `like()`, so a UI
     * can render straight from the response with no follow-up read.
     *
     * @throws GurbApiException
     */
    public function unlike(CommentParent $parent, string $parentId, string $commentId): LikeState
    {
        return LikeState::fromArray(
            $this->requester->request('DELETE', $this->likePath($parent, $parentId, $commentId)),
        );
    }

    /**
     * `sdk/{parent}/{parentId}/comments`.
     *
     * `$parent` is an enum, so its segment is a value from a closed set and
     * cannot be caller text. `$parentId` IS caller text, and is encoded: an id
     * containing a slash would otherwise walk the request onto a path this SDK
     * never meant to call, which on this backend answers 400 "Tenant context
     * required" rather than 404 — sending the integrator to audit credentials
     * over a bad id.
     */
    private function base(CommentParent $parent, string $parentId): string
    {
        return 'sdk/' . $parent->value . '/' . \rawurlencode($parentId) . '/comments';
    }

    private function likePath(CommentParent $parent, string $parentId, string $commentId): string
    {
        return $this->base($parent, $parentId) . '/' . \rawurlencode($commentId) . '/like';
    }
}
