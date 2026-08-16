<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Blog;
use Gurb\Model\Paginated;

final class BlogsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Blog>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when blogs are not on this
     *                          community's plan — the module is a plan toggle,
     *                          so this is an expected outcome, not a bug.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/blogs', ['page' => $page, 'limit' => $limit]),
            Blog::fromArray(...),
        );
    }

    /**
     * Create a blog.
     *
     * Requires the `blogs` feature on the plan; a community without it answers
     * 403 FEATURE_NOT_AVAILABLE on every call here, reads included.
     *
     * The key's owner needs `blogs:manage` OR `blogs:create`. Every write in this SDK uses the SAME
     * permission Gurb's own web app checks — never stricter, never looser — so
     * the requirement differs per section and cannot be unified. A plain member
     * holds none of these: in Gurb `MEMBER` is an empty permission set and
     * publishing is a capability an admin grants. A key whose owner was granted
     * nothing reads everything and publishes nothing, which is correct rather
     * than a misconfiguration.
     *
     * `status: 'DRAFT'` publishes nothing and announces nothing. Deleting is a
     * SOFT delete upstream, and fires exactly one `blog.deleted`.
     *
     * @param array<string, mixed> $options Any other field the endpoint accepts.
     *                                      Unknown keys are ignored by the server.
     *
     * @throws GurbApiException
     */
    public function create(
        string $title,
        string $contentHtml,
        array $options = [],
    ): Blog {
        $body = \array_merge($options, [
            'title' => $title,
            // Rendered HTML, stored as sent.
            'contentHtml' => $contentHtml,
        ]);

        return Blog::fromArray($this->requester->request('POST', 'sdk/blogs', body: $body));
    }

    /**
     * Update a blog.
     *
     * The key's owner may ALWAYS edit what it created — authorship is checked
     * before permissions, exactly as the web app checks it. Editing anyone
     * else's needs the section's `*:manage` or `*:moderate`, so expect 403s on
     * content your key did not create.
     *
     * @param array<string, mixed> $fields Only what you want changed.
     *
     * @throws GurbApiException
     */
    public function update(string $blogId, array $fields): Blog
    {
        return Blog::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/blogs/' . \rawurlencode($blogId),
            body: $fields,
        ));
    }

    /**
     * Delete a blog. Same authorship-or-moderation rule as update().
     *
     * @throws GurbApiException
     */
    public function delete(string $blogId): void
    {
        $this->requester->request('DELETE', 'sdk/blogs/' . \rawurlencode($blogId));
    }
}
