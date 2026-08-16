<?php

declare(strict_types=1);

namespace Gurb\Resource;

use Gurb\GurbApiException;
use Gurb\Input\CreateCommunityInput;
use Gurb\Internal\Requester;
use Gurb\Model\Community;
use Gurb\Model\CommunityProfile;
use Gurb\Model\CommunityRequest;
use Gurb\Model\CommunitySettings;
use Gurb\Model\LegalDocuments;

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

    // ─── Settings ────────────────────────────────────────────────────────────

    /**
     * How this community is configured: menu visibility, home widgets, guest
     * access, direct messages — plus its colours, which travel with this read
     * because it is the one endpoint that returns them.
     *
     * NO PERMISSION IS NEEDED. Gurb serves this to anonymous visitors — a guest
     * landing on a community page has to know which sections to render before
     * logging in — and an API key is strictly more credentialed than the open
     * internet, so requiring anything here would refuse a read a stranger can
     * already perform. No feature gate either.
     *
     * @throws GurbApiException
     */
    public function getSettings(): CommunitySettings
    {
        return CommunitySettings::fromArray($this->requester->request('GET', 'sdk/community/settings'));
    }

    /**
     * Change some settings.
     *
     * ⚠️ SEND ONLY THE KEYS YOU MEAN TO CHANGE. NEVER READ THE SETTINGS AND
     * WRITE THE WHOLE BLOB BACK. This is the single most dangerous method in the
     * SDK to use carelessly, and the reason is the merge on the other side:
     *
     *     stored = { ...stored, ...yourPatch }
     *
     * with a DEEP merge for `pageVisibility` and nothing else. Every other key
     * you send REPLACES its stored counterpart WHOLESALE. So a read-modify-write
     * round trip — the shape everyone reaches for — overwrites `homeWidgets`,
     * `widgetConfig`, `guestAccess` and the rest with whatever your process
     * happened to be holding, and if anything was stale or decoded to a default,
     * the community's layout is silently gone. The response says success. Nobody
     * finds out until a member reports a missing menu.
     *
     * `pageVisibility` is the safe one, and only because of that deep merge: a
     * patch of `['news' => false]` leaves every other menu entry exactly as it
     * was. `pageVisibility.home` is always forced true by the server whatever
     * you send.
     *
     * Requires `settings:manage` OR `homepage:customize`. Every write in this
     * SDK uses the SAME permission Gurb's own web app checks — never stricter,
     * never looser — so requirements differ per section and cannot be unified. A
     * plain member holds neither of these: `MEMBER` is an empty permission set
     * in Gurb, so a key whose owner was granted nothing READS this and cannot
     * write it. Correct, not a misconfiguration.
     *
     * @param array<string, mixed> $patch Only the keys you are changing.
     *                                    Accepted: `pageVisibility`,
     *                                    `homeWidgets`, `advertisements`,
     *                                    `directMessagesEnabled`, `backButton`,
     *                                    `widgetConfig`, `guestAccess`. Anything
     *                                    else is REFUSED rather than dropped —
     *                                    a misspelled `pageVisibilty` that
     *                                    answered 200 would be believed.
     *                                    `featureOverrides` is never accepted:
     *                                    it is where a community's paid
     *                                    entitlements live.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          for an empty patch.
     */
    public function updateSettings(array $patch): CommunitySettings
    {
        if ($patch === []) {
            // Not a harmless no-op: an empty patch almost always means the
            // caller built it from a filter that matched nothing, and sending a
            // request that changes nothing would hide that.
            throw GurbApiException::localValidation(
                'updateSettings needs at least one key to change.',
            );
        }

        return CommunitySettings::fromArray(
            $this->requester->request('PATCH', 'sdk/community/settings', body: $patch),
        );
    }

    // ─── Branding ────────────────────────────────────────────────────────────

    /**
     * Rename the community, retint it, move it, or change its VISIBILITY.
     *
     * ⚠️ `type` IS NOT A FLAG. Flipping PUBLIC → PRIVATE resets the
     * guest-preview opt-in on EVERY row of EVERY section, in the same
     * transaction, and flipping back does NOT restore them — an admin has to
     * re-pick each one. It is on this contract because Gurb's own route exposes
     * it under exactly the same permission, and refusing it here would leave an
     * admin unable to do through the API what they can do in the dashboard,
     * with nothing to explain why. A "safer" divergence is still a divergence.
     *
     * There is no `isPublic`, and sending it is a 400 rather than a silent
     * ignore. Visibility is `type` alone; the boolean it replaced was a
     * duplicate that desynced.
     *
     * `logo`, `logoUrl`, `coverImage` and `bannerUrl` are REFUSED for the same
     * reason: images are file uploads, which this surface does not have, and a
     * URL string would be accepted and then do nothing — the worst possible
     * answer.
     *
     * Requires `settings:manage` OR `homepage:customize` — the same rule as
     * `updateSettings()`, because upstream they are the same route and the same
     * check. A plain member holds neither.
     *
     * @param array<string, mixed> $fields `name`, `nameArabic`, `description`,
     *                                     `descriptionArabic`, `type`
     *                                     (`PUBLIC` | `PRIVATE`),
     *                                     `primaryColor`, `secondaryColor`,
     *                                     `category`, `country`, `city`.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          for an empty patch.
     */
    public function updateBranding(array $fields): CommunityProfile
    {
        if ($fields === []) {
            throw GurbApiException::localValidation(
                'updateBranding needs at least one field to change.',
            );
        }

        return CommunityProfile::fromArray(
            $this->requester->request('PATCH', 'sdk/community/branding', body: $fields),
        );
    }

    // ─── Legal ───────────────────────────────────────────────────────────────

    /**
     * The community's own terms of use and privacy policy.
     *
     * NO PERMISSION, and no feature gate — deliberately public upstream for
     * PRIVATE communities too, because a prospective member has to read the
     * rules before agreeing to them.
     *
     * @throws GurbApiException
     */
    public function getLegal(): LegalDocuments
    {
        return LegalDocuments::fromArray($this->requester->request('GET', 'sdk/community/legal'));
    }

    /**
     * Set the community's terms and/or privacy policy.
     *
     * AN EMPTY STRING IS A REAL INSTRUCTION AND IS NOT THE SAME AS OMITTING THE
     * FIELD. `''` RESTORES the platform's standard template for that document in
     * the community's language; `null` — the default — leaves the stored text
     * alone and is not sent at all. Conflating the two produces one of two real
     * bugs: a deliberate reset ignored, or someone's hand-written terms wiped by
     * a call that meant to touch neither.
     *
     * Requires `settings:manage` OR `homepage:customize`: upstream this is the
     * same route and the same check as `updateSettings()`. A plain member holds
     * neither, so a key whose owner was granted nothing can READ the terms and
     * not change them.
     *
     * `$language` alone changes nothing observable — it only decides which
     * standard template a BLANK field resolves to — so it is not accepted on its
     * own.
     *
     * @param string|null $terms         `''` restores the standard template.
     * @param string|null $privacyPolicy `''` restores the standard template.
     * @param string|null $language      `ar` or `en`.
     *
     * @throws GurbApiException VALIDATION_ERROR (status 0) with NO request made
     *                          when neither text is provided. Oversized text is
     *                          refused by the server as LEGAL_TEXT_TOO_LONG.
     */
    public function updateLegal(
        ?string $terms = null,
        ?string $privacyPolicy = null,
        ?string $language = null,
    ): LegalDocuments {
        if ($terms === null && $privacyPolicy === null) {
            throw GurbApiException::localValidation(
                'updateLegal needs terms and/or privacyPolicy. Pass "" to restore the standard template.',
            );
        }

        // Each field is OMITTED when null rather than sent as null, because
        // absence is what the server reads as "keep what is stored" — and it
        // makes that decision by asking whether the KEY IS PRESENT, so a null
        // would read as a deliberate clear.
        $body = [];
        if ($terms !== null) {
            $body['terms'] = $terms;
        }
        if ($privacyPolicy !== null) {
            $body['privacyPolicy'] = $privacyPolicy;
        }
        if ($language !== null) {
            $body['language'] = $language;
        }

        return LegalDocuments::fromArray(
            $this->requester->request('PATCH', 'sdk/community/legal', body: $body),
        );
    }
}
