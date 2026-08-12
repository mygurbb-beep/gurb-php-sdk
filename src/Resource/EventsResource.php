<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Event;
use Gurb\Model\Paginated;

final class EventsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * @param bool|null $upcoming Null means "let the server decide" — which is
     *                            not the same as false, so it is not defaulted.
     *
     * @return Paginated<Event>
     *
     * @throws GurbApiException
     */
    public function list(?int $page = null, ?int $limit = null, ?bool $upcoming = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/events', [
                'page' => $page,
                'limit' => $limit,
                'upcoming' => $upcoming,
            ]),
            Event::fromArray(...),
        );
    }
}
