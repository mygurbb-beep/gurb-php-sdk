<?php

declare(strict_types=1);

namespace Gurb;

/**
 * Where a community-creation request sits in the approval queue.
 *
 * Used as a *filter* on the list endpoints. It is an enum on the way out (you
 * choose from three) but the decoded `CommunityRequest::$status` is a plain
 * string on the way in — see the note on that class. Strict where you write,
 * tolerant where you read.
 */
enum CommunityRequestStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
