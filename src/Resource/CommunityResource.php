<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Input\CreateCommunityInput;
use Gurb\Internal\Requester;
use Gurb\Model\Community;
use Gurb\Model\CommunityRequest;

final class CommunityResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * The community this key is pinned to.
     *
     * Note there is no `$communityId` argument on this method. The key carries
     * the community, so a leaked key damages exactly one community and can never
     * be pointed at another one.
     *
     * @throws GurbApiException
     */
    public function get(): Community
    {
        return Community::fromArray($this->requester->request('GET', 'sdk/community'));
    }

    /**
     * Ask for a new community to be created.
     *
     * A COMMUNITY KEY CAN NEVER CREATE A COMMUNITY OUTRIGHT — that is a
     * super-admin operation, and if this method returned a `Community` the whole
     * two-credential split would be decorative. What comes back is a
     * `CommunityRequest` with status PENDING: a human has to approve it, which
     * is the same gate the dashboard uses.
     *
     * So do not assume success. Poll `$gurb->communityRequests->get($id)` until
     * `isPending()` is false, or subscribe to the `community.approved` webhook.
     * The answer may also be no, with a reason on `$request->rejectionReason`.
     *
     * @throws GurbApiException VALIDATION_ERROR when the slug is already taken —
     *                          slugs are unique platform-wide, so this is a normal
     *                          outcome to handle, not an exceptional one.
     */
    public function requestCreation(CreateCommunityInput $input): CommunityRequest
    {
        return CommunityRequest::fromArray(
            $this->requester->request('POST', 'sdk/community-requests', body: $input->toArray()),
        );
    }
}
