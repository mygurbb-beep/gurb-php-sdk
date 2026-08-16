<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Album;
use Gurb\Model\Paginated;

final class AlbumsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<Album>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/albums', ['page' => $page, 'limit' => $limit]),
            Album::fromArray(...),
        );
    }

    /**
     * Create a album.
     *
     * Requires the `albums` feature on the plan.
     *
     * The key's owner needs `albums:manage` OR `albums:create`. Every write in this SDK uses the SAME
     * permission Gurb's own web app checks — never stricter, never looser — so
     * the requirement differs per section and cannot be unified. A plain member
     * holds none of these: in Gurb `MEMBER` is an empty permission set and
     * publishing is a capability an admin grants. A key whose owner was granted
     * nothing reads everything and publishes nothing, which is correct rather
     * than a misconfiguration.
     *
     * THE ALBUM IS CREATED EMPTY. Adding images is not supported on this
     * surface yet — uploads need storage-budget accounting, and shipping them
     * without it would let a community exceed the storage its plan pays for.
     * Members can still add images through the app.
     *
     * @param array<string, mixed> $options Any other field the endpoint accepts.
     *                                      Unknown keys are ignored by the server.
     *
     * @throws GurbApiException
     */
    public function create(
        string $title,
        array $options = [],
    ): Album {
        $body = \array_merge($options, ['title' => $title]);

        return Album::fromArray($this->requester->request('POST', 'sdk/albums', body: $body));
    }

    /**
     * Update a album.
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
    public function update(string $albumId, array $fields): Album
    {
        return Album::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/albums/' . \rawurlencode($albumId),
            body: $fields,
        ));
    }

    /**
     * Delete a album. Same authorship-or-moderation rule as update().
     *
     * @throws GurbApiException
     */
    public function delete(string $albumId): void
    {
        $this->requester->request('DELETE', 'sdk/albums/' . \rawurlencode($albumId));
    }
}
