<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\LikeParent;
use Gurb\Model\LikeState;

/**
 * Liking a post, a blog or an album — as the key's owner.
 *
 * LIKING NEEDS NO PERMISSION. Like commenting, and unlike posting, it is what an
 * ordinary member does: Gurb's own like routes are authentication, membership
 * and a rate limiter, with no permission guard anywhere, and this SDK mirrors
 * that exactly. A key whose owner was granted nothing — every plain member,
 * since `MEMBER` is an empty permission set in Gurb — cannot publish a post and
 * CAN like one. Requiring something here "because it is a public API" would
 * refuse the exact call the product allows.
 *
 * THREE PARENTS, NOT FOUR. `LikeParent` has no `Events` case because the
 * platform has no event like — its own service answers "not yet implemented" and
 * publishes no route. Comments are likeable too, but through `CommentsResource`:
 * a comment like is addressed by parent AND comment id.
 *
 * WHAT CAN REFUSE YOU:
 *
 *   - the parent's FEATURE GATE. Blogs and albums sit behind their module's plan
 *     flag, so a community without it gets 403 FEATURE_NOT_AVAILABLE — on the
 *     read as well as the write. Tweets carry no gate. That asymmetry is real
 *     and is not smoothed out. An entitlement is not a permission and no grant
 *     fixes one.
 *   - MODERATION, on tweets ONLY. `LikeParent::Tweets` is the one parent whose
 *     like runs the community's member-restriction check, so a key owner who has
 *     been muted from liking gets 403 RESTRICTION_BLOCKED here and nowhere else.
 *   - the RATE LIMITER, on both liking and unliking.
 */
final class LikesResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Does the KEY'S OWNER like this, and how many people do?
     *
     * `LikeState::$liked` is about the one human the key was minted for. An
     * integration acting for many end users cannot ask "did THIS visitor like
     * it" with a server key — that is what embed sessions are for. `$likeCount`
     * is everybody's.
     *
     * @throws GurbApiException
     */
    public function state(LikeParent $parent, string $parentId): LikeState
    {
        return LikeState::fromArray(
            $this->requester->request('GET', $this->path($parent, $parentId)),
        );
    }

    /**
     * Like, as the key's owner. Idempotent — liking twice leaves one like.
     *
     * @throws GurbApiException RESTRICTION_BLOCKED (403) when the key's owner is
     *                          restricted from liking in this community. Only
     *                          `LikeParent::Tweets` can answer that.
     */
    public function like(LikeParent $parent, string $parentId): LikeState
    {
        return LikeState::fromArray(
            $this->requester->request('POST', $this->path($parent, $parentId)),
        );
    }

    /**
     * Remove the key owner's like. Answers the same shape as `like()` and
     * `state()`, so a UI can render straight from the response of the action it
     * just took, with no follow-up read.
     *
     * @throws GurbApiException
     */
    public function unlike(LikeParent $parent, string $parentId): LikeState
    {
        return LikeState::fromArray(
            $this->requester->request('DELETE', $this->path($parent, $parentId)),
        );
    }

    /**
     * `sdk/{parent}/{parentId}/like`.
     *
     * `$parent` is an enum, so that segment comes from a closed set. `$parentId`
     * is caller text and is encoded: an unencoded "a/b" would turn this into a
     * path this SDK never meant to call, and an unmatched `/api/*` on this
     * backend answers 400 "Tenant context required" rather than 404 — which
     * sends an integrator to audit their credentials over a bad id.
     */
    private function path(LikeParent $parent, string $parentId): string
    {
        return 'sdk/' . $parent->value . '/' . \rawurlencode($parentId) . '/like';
    }
}
