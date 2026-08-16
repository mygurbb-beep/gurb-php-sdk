<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Award;
use Gurb\Model\Paginated;

final class AwardsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * The catalogue of medals this community defines.
     *
     * THIS IS NOT A LIST OF AWARDS ANYONE RECEIVED. Each row is a medal that
     * exists; who holds it, and since when, lives on the grant and not here —
     * which is why every `Award::$awardedAt` comes back null. Read the note on
     * that property before displaying a date next to one of these.
     *
     * @return Paginated<Award>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when awards are not on this
     *                          community's plan.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/awards', ['page' => $page, 'limit' => $limit]),
            Award::fromArray(...),
        );
    }
}
