<?php

declare(strict_types=1);

namespace Gurb\Resource\Admin;

use Gurb\CommunityRequestStatus;
use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\CommunityRequest;
use Gurb\Model\Paginated;

/**
 * The approval queue: every community-creation request, from everyone.
 *
 * The community-side class of the same name can only read its own requests.
 * This one can read all of them and decide.
 */
final class AdminCommunityRequestsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @param CommunityRequestStatus|null $status Null lists everything. Pass
     *                                            `Pending` for the actual queue —
     *                                            the decided ones pile up forever
     *                                            and will otherwise bury it.
     *
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
            $this->requester->request('GET', 'admin/sdk/community-requests', [
                'status' => $status?->value,
                'page' => $page,
                'limit' => $limit,
            ]),
            CommunityRequest::fromArray(...),
        );
    }

    /** @throws GurbApiException */
    public function get(string $requestId): CommunityRequest
    {
        return CommunityRequest::fromArray(
            $this->requester->request('GET', 'admin/sdk/community-requests/' . \rawurlencode($requestId)),
        );
    }

    /**
     * Approve a pending request. The community is created as part of this call,
     * and its id comes back on `CommunityRequest::$communityId`.
     *
     * Approving an already-decided request FAILS rather than creating a second
     * community: the decision is what is idempotent, not the click. A retried
     * approve after a timeout therefore gets a 400 telling you it was already
     * approved, which is the answer you wanted anyway.
     *
     * @throws GurbApiException VALIDATION_ERROR if the request was already decided.
     */
    public function approve(string $requestId): CommunityRequest
    {
        // No body: the request id in the URL and the verb are the whole meaning.
        return CommunityRequest::fromArray($this->requester->request(
            'POST',
            'admin/sdk/community-requests/' . \rawurlencode($requestId) . '/approve',
        ));
    }

    /**
     * Reject a pending request.
     *
     * A REASON IS REQUIRED, and required HERE rather than only on the server —
     * because the requester is shown it, and "your community was rejected" with
     * no explanation generates a support ticket every single time. Making it a
     * mandatory parameter means you have to at least think of one word.
     *
     * The check runs before any HTTP call, so a missing reason costs a
     * round-trip to nobody.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) if the reason is empty
     *                          or only whitespace, with no HTTP call made.
     */
    public function reject(string $requestId, string $reason): CommunityRequest
    {
        $trimmed = \trim($reason);
        if ($trimmed === '') {
            throw GurbApiException::localValidation(
                'A rejection reason is required — the requester is shown it.',
            );
        }

        return CommunityRequest::fromArray($this->requester->request(
            'POST',
            'admin/sdk/community-requests/' . \rawurlencode($requestId) . '/reject',
            // Trimmed, not raw: a reason of "  ok  " is stored as "ok". Sending
            // whitespace we already decided was meaningless would be odd.
            body: ['reason' => $trimmed],
        ));
    }
}
