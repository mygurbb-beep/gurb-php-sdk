<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\CommunityRequestStatus;
use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\CommunityRequest;
use Gurb\Model\Paginated;

/**
 * The requests THIS key has filed, and what happened to them.
 *
 * Read-only, and that is the entire point of the split: a community key files a
 * request and then watches. Approving one is on `GurbAdminClient` and needs a
 * different credential — see `Gurb\Resource\Admin\AdminCommunityRequestsResource`.
 * If you find yourself wanting `approve()` here, what you actually want is a
 * super-admin key, and you should want to think about that for a moment first.
 */
final class CommunityRequestsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @return Paginated<CommunityRequest>
     *
     * @throws GurbApiException
     */
    public function list(
        ?CommunityRequestStatus $status = null,
        ?int $page = null,
        ?int $limit = null,
    ): Paginated {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/community-requests', [
                'status' => $status?->value,
                'page' => $page,
                'limit' => $limit,
            ]),
            CommunityRequest::fromArray(...),
        );
    }

    /**
     * Poll this, or subscribe to the `community.approved` webhook — but do not
     * assume a filed request became a community. A human has to say yes, and
     * the answer may be no with a reason attached.
     *
     * @throws GurbApiException NOT_FOUND if no such request, or if it belongs to
     *                          someone else — those are the same 404 on purpose,
     *                          so this route cannot be used to probe for ids.
     */
    public function get(string $requestId): CommunityRequest
    {
        return CommunityRequest::fromArray(
            $this->requester->request('GET', 'sdk/community-requests/' . \rawurlencode($requestId)),
        );
    }
}
