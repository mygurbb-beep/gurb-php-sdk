<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Internal\Requester;
use Gurb\Model\Group;
use Gurb\Model\Paginated;

final class GroupsResource
{
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Every group in this community, private ones included.
     *
     * The list is not filtered by visibility — see `Group::$isPrivate` for why,
     * and for the responsibility that comes with it.
     *
     * @return Paginated<Group>
     *
     * @throws GurbApiException FEATURE_NOT_AVAILABLE when groups are not on this
     *                          community's plan — the module is a plan toggle,
     *                          so this is an expected outcome, not a bug.
     */
    public function list(?int $page = null, ?int $limit = null): Paginated
    {
        return Paginated::fromArray(
            $this->requester->request('GET', 'sdk/groups', ['page' => $page, 'limit' => $limit]),
            Group::fromArray(...),
        );
    }

    /**
     * Create a group.
     *
     * Requires the `groups` feature on the community's plan. A community without
     * it answers 403 FEATURE_NOT_AVAILABLE on EVERY call here, reads included —
     * that is an entitlement, not a permission, and no grant will fix it.
     *
     * The key's owner needs `groups:manage` OR `groups:create`. Every write in
     * this SDK uses the SAME permission Gurb's own web app checks — never
     * stricter, never looser — so the requirement differs per section and cannot
     * be unified. A plain member holds none of these: in Gurb `MEMBER` is an
     * empty permission set and creating is a capability an admin grants. A key
     * whose owner was granted nothing reads everything and publishes nothing,
     * which is correct rather than a misconfiguration.
     *
     * `$iconUrl` IS REQUIRED, and that is a consequence of there being no
     * uploads on this surface. Gurb's own route takes an icon FILE and its
     * service refuses a group without one, so the URL string occupies the
     * argument the uploaded file's URL would have filled. It must be http(s).
     *
     * `$isPrivate` is required rather than defaulted, because defaulting a
     * VISIBILITY field is how a private group gets published by accident.
     *
     * Arabic: `nameArabic` and `descriptionArabic` default to their non-Arabic
     * counterparts server-side. Both columns are NOT NULL and Gurb's own UI is
     * Arabic-first; pass them in `$options` when you have real translations.
     *
     * @param array<string, mixed> $options `nameArabic`, `descriptionArabic`,
     *                                      `memberLimit` (-1 = unlimited),
     *                                      `canChat`, `email`, `website`,
     *                                      `coverUrl`.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          when name, description or iconUrl is blank.
     */
    public function create(
        string $name,
        string $description,
        bool $isPrivate,
        string $iconUrl,
        array $options = [],
    ): Group {
        $trimmedName = \trim($name);
        $trimmedDescription = \trim($description);
        $trimmedIconUrl = \trim($iconUrl);

        if ($trimmedName === '' || $trimmedDescription === '' || $trimmedIconUrl === '') {
            // Caught locally so a half-built form costs no round trip and no
            // rate-limit budget. The server refuses it too; this only makes the
            // failure immediate and the message specific.
            throw GurbApiException::localValidation(
                'A group needs a name, a description and an http(s) iconUrl.',
            );
        }

        // NOTE: the trimmed values are what get SENT, not merely what got
        // validated. Validating one string and transmitting another is an easy
        // and real bug — the local check passes and the server still sees
        // leading whitespace.
        $body = \array_merge($options, [
            'name' => $trimmedName,
            'description' => $trimmedDescription,
            'isPrivate' => $isPrivate,
            'iconUrl' => $trimmedIconUrl,
        ]);

        return Group::fromArray($this->requester->request('POST', 'sdk/groups', body: $body));
    }

    /**
     * Update a group.
     *
     * The key's owner may edit what it created — authorship is checked FIRST,
     * exactly as the web app checks it — and editing anyone else's group needs
     * `groups:manage`, `groups:create` or `groups:moderate`. Expect 403s on
     * groups your key did not create.
     *
     * AN IMAGE CANNOT BE CLEARED HERE. `iconUrl: null` and `coverUrl: null` are
     * refused by the server rather than silently ignored, because upstream a
     * falsy value is indistinguishable from an absent one and would answer 200
     * while changing nothing.
     *
     * @param array<string, mixed> $fields Only what you want changed: `name`,
     *                                     `nameArabic`, `description`,
     *                                     `descriptionArabic`, `isPrivate`,
     *                                     `memberLimit`, `canChat`, `email`,
     *                                     `website`, `iconUrl`, `coverUrl`.
     *                                     An empty array is a 400, not a no-op.
     *
     * @throws GurbApiException
     */
    public function update(string $groupId, array $fields): Group
    {
        return Group::fromArray($this->requester->request(
            'PATCH',
            // rawurlencode, so a slash inside a caller-supplied id cannot walk
            // the request onto a path this SDK never meant to call.
            'sdk/groups/' . \rawurlencode($groupId),
            body: $fields,
        ));
    }

    /**
     * Delete a group.
     *
     * THE ONE PLACE ON THIS SURFACE WHERE DELETE IS NOT UPDATE-MINUS-CREATE, and
     * it is not a bug: `groups:manage` or `groups:moderate` ONLY, with NO
     * authorship shortcut. A group's creator may rename their group and may not
     * delete it. That asymmetry is deliberate upstream — the permission was
     * widened toward moderators, not toward authors — and it is mirrored here
     * rather than smoothed out.
     *
     * @throws GurbApiException
     */
    public function delete(string $groupId): void
    {
        $this->requester->request('DELETE', 'sdk/groups/' . \rawurlencode($groupId));
    }
}
