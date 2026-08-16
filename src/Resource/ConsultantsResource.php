<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Consultant;
use Gurb\Model\Paginated;

final class ConsultantsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * The consultancy listings this community publishes.
     *
     * These are OFFERED SERVICES, not staff records — read the note on
     * `Consultant::$title` before you design a card around one.
     *
     * @return Paginated<Consultant>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when consultants are not on
     *                          this community's plan.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/consultants', ['page' => $page, 'limit' => $limit]),
            Consultant::fromArray(...),
        );
    }
}
