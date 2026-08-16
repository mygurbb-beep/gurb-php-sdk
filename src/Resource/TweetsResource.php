<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Paginated;
use Gurb\Model\Tweet;

final class TweetsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Tweet>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/tweets', ['page' => $page, 'limit' => $limit]),
            Tweet::fromArray(...),
        );
    }

    /**
     * Publish a post to the community's home feed, or into one of its groups.
     *
     * WHAT THIS REQUIRES, AND WHY IT IS NOT THE SAME AS THE OTHER SECTIONS
     *
     * The key's owner needs `posts:manage` OR `posts:create`. Every write in
     * this SDK is guarded by the SAME permission Gurb's own web app checks for
     * that action — never a stricter one and never a looser one — so the
     * requirements differ per section and cannot be unified. A community admin
     * who grants `blogs:create` in the dashboard expects that grant to mean the
     * same thing through an integration; any divergence would make the
     * dashboard's permission screen a lie.
     *
     * A PLAIN MEMBER HOLDS NONE OF THESE. In Gurb, `MEMBER` resolves to an empty
     * permission set and posting is a capability an admin grants. So a key whose
     * owner was never granted anything can read everything and publish nothing.
     * That is correct behaviour, not a misconfiguration — do not read the first
     * 403 as a bug in this library.
     *
     * Attachments are NOT supported here. Uploads need storage-budget
     * accounting this surface does not implement yet, and shipping them without
     * it would let a community silently exceed the storage its plan pays for.
     *
     * @param string|null $groupId Post into a group instead of the home feed.
     *                             Checked against that group's own rules, which
     *                             are not the same as the home feed's.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) for empty content,
     *                          with NO request made.
     */
    public function create(
        string $content,
        ?string $groupId = null,
        ?bool $allowComments = null,
    ): Tweet {
        $trimmed = \trim($content);
        if ($trimmed === '') {
            // Caught locally so an empty draft costs no round trip and no
            // rate-limit budget. The server refuses it too; this only makes the
            // failure immediate and the message specific.
            throw GurbApiException::localValidation('A post needs content.');
        }

        // NOTE: the trimmed value is what gets SENT, not merely what got
        // validated. Validating one string and transmitting another is an easy
        // and real bug — the local check passes and the server still sees
        // leading whitespace.
        $body = ['content' => $trimmed];
        if ($groupId !== null) {
            $body['groupId'] = $groupId;
        }
        if ($allowComments !== null) {
            $body['allowComments'] = $allowComments;
        }

        return Tweet::fromArray($this->requester->request('POST', 'sdk/tweets', body: $body));
    }

    /**
     * Edit a post.
     *
     * The key's owner may ALWAYS edit what it published — authorship is checked
     * before permissions, exactly as the web app checks it. Editing anyone
     * else's post needs `posts:manage` or `posts:moderate`, so expect 403s when
     * operating on content your key did not create.
     *
     * @throws GurbApiException
     */
    public function update(string $tweetId, ?string $content = null, ?bool $allowComments = null): Tweet
    {
        $body = [];
        if ($content !== null) {
            $body['content'] = $content;
        }
        if ($allowComments !== null) {
            $body['allowComments'] = $allowComments;
        }

        return Tweet::fromArray(
            $this->requester->request('PATCH', 'sdk/tweets/' . \rawurlencode($tweetId), body: $body),
        );
    }

    /**
     * Delete a post. Same authorship-or-moderation rule as update().
     *
     * @throws GurbApiException
     */
    public function delete(string $tweetId): void
    {
        $this->requester->request('DELETE', 'sdk/tweets/' . \rawurlencode($tweetId));
    }
}
