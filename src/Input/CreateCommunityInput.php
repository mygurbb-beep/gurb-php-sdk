<?php

declare(strict_types=1);

namespace Gurb\Input;

/**
 * What you describe when you want to ASK for a community.
 *
 *   $gurb->requestCommunityCreation($input)   → files a request. PENDING.
 *
 * TWO FIELDS, as of 0.4.0. `description`, `type` and `ownerUserId` were removed
 * rather than accepted and ignored: community creation is a name and a slug on
 * both endpoints now, visibility is decided server-side (PRIVATE), and the owner
 * comes from the credential so the audit trail names whoever performed the
 * action rather than whoever was named in a body.
 *
 * The sibling operation, `$admin->communities->create(name:, slug:)`, takes the
 * same two values as plain arguments — it has no optional fields left to justify
 * an object, and named arguments read the same at the call site.
 *
 * An input object carries no authority — it is a bag of strings until a client
 * with a key sends it somewhere — which is why the dangerous half of that pair
 * is the credential, not the payload. See the class comment on GurbAdminClient.
 *
 * Note that `CommunityRequest::$description` still exists on the way BACK. A
 * response field is not a request field: older requests carry one, and removing
 * it from the model would drop data the server still returns.
 */
final class CreateCommunityInput
{
    /**
     * @param string $name The human-facing name, usually Arabic. 1–100 characters.
     * @param string $slug The URL segment: lowercase letters, digits and hyphens,
     *                     3–50 characters, no leading, trailing or repeated
     *                     hyphen. Globally unique across the platform, so "taken"
     *                     is a normal outcome that arrives as a 400
     *                     VALIDATION_ERROR and not a bug.
     *
     *                     NOT validated here. This object is a carrier, and the
     *                     request-creation endpoint is the server's to police —
     *                     `AdminCommunitiesResource::create()` checks the rules
     *                     locally because it is the direct-creation path, and
     *                     that is where the TypeScript SDK checks them too.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }
}
