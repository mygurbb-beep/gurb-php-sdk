<?php

declare(strict_types=1);

namespace Gurb\Input;

/**
 * What you describe when you want a community to exist.
 *
 * ONE INPUT OBJECT, TWO VERY DIFFERENT OPERATIONS. The same value goes to:
 *
 *   $gurb->requestCommunityCreation($input)   → files a request. PENDING.
 *   $admin->communities->create($input)       → the community exists. Now.
 *
 * The payload is identical; the authority behind it is not. That is exactly why
 * the two live on different classes holding different credentials — see the
 * class comment on GurbAdminClient. Sharing the input object is safe because an
 * input object carries no authority: it is a bag of strings until a client with
 * a key sends it somewhere.
 */
final class CreateCommunityInput
{
    /**
     * @param string      $slug        The URL segment. Globally unique across the
     *                                 platform, so "taken" is a normal outcome and
     *                                 arrives as a 400 VALIDATION_ERROR, not a bug.
     * @param string|null $type        'PUBLIC' or 'PRIVATE'. Left as a string rather
     *                                 than an enum to match `Community::$type` on the
     *                                 way back, and because the server owns the
     *                                 default (PRIVATE) — null here means "you
     *                                 decide", which is not the same as PRIVATE and
     *                                 so must stay expressible.
     * @param string|null $ownerUserId A `User.id`, NOT a `CommunityMember.id`. The
     *                                 two are different ids on this platform and
     *                                 confusing them is its most common bug; the
     *                                 owner of a community that does not exist yet
     *                                 cannot be a member of it, so only the platform
     *                                 id makes sense. Null means "whoever asked".
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description = null,
        public readonly ?string $type = null,
        public readonly ?string $ownerUserId = null,
    ) {
    }

    /**
     * Nulls are OMITTED rather than sent, so the body matches the TypeScript
     * SDK's byte for byte and the backend's optional-field validation sees
     * "absent" instead of "explicitly null". Those are different things to a
     * validator that treats null as a value.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $body = [
            'name' => $this->name,
            'slug' => $this->slug,
        ];

        if ($this->description !== null) {
            $body['description'] = $this->description;
        }
        if ($this->type !== null) {
            $body['type'] = $this->type;
        }
        if ($this->ownerUserId !== null) {
            $body['ownerUserId'] = $this->ownerUserId;
        }

        return $body;
    }
}
