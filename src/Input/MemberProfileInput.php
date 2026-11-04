<?php

declare(strict_types=1);

namespace Gurb\Input;

/**
 * Profile fields a partner may send for one person (1.11.0). The same object
 * rides on a {@see BulkMemberInput} row and on GurbClient::createEmbedSession().
 *
 * NULL IS "UNTOUCHED", '' IS "CLEAR". The wire also accepts an explicit null as
 * clear, but a named argument cannot tell "not passed" from "passed null", and
 * omitting an untouched field matters more: sending every field on every sync
 * would wipe what you did not mean to. So this class omits nulls, and you clear
 * a field with an empty string.
 *
 * A field the member has edited in Gurb is KEPT and your value is silently
 * ignored. Directory privacy is never written: `$phone` and `$whatsapp` stay
 * hidden from other members until the member opts in.
 *
 * A wrong value (over its limit): on bulkUpsert that ROW fails with
 * VALIDATION_ERROR and nothing on it is written; on createEmbedSession that one
 * field is dropped and the sign-in goes ahead, like a bad avatarUrl. The limits
 * are the server's — this class adds no local guard.
 */
final class MemberProfileInput
{
    /**
     * @param string|null $bio      ≤ 200. Stored as the profile description.
     * @param string|null $jobTitle ≤ 200. Stored as `position`.
     * @param string|null $company  ≤ 200.
     * @param string|null $industry ≤ 200.
     * @param string|null $city     ≤ 100.
     * @param string|null $phone    Normalised like the member's own form. Hidden by default.
     * @param string|null $whatsapp ≤ 50. Hidden by default (follows phone).
     */
    public function __construct(
        public readonly ?string $bio = null,
        public readonly ?string $jobTitle = null,
        public readonly ?string $company = null,
        public readonly ?string $industry = null,
        public readonly ?string $city = null,
        public readonly ?string $phone = null,
        public readonly ?string $whatsapp = null,
        public readonly ?MemberSocialLinks $social = null,
    ) {
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    public function toArray(): array
    {
        $profile = \array_filter([
            'bio' => $this->bio,
            'jobTitle' => $this->jobTitle,
            'company' => $this->company,
            'industry' => $this->industry,
            'city' => $this->city,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
        ], static fn (?string $value): bool => $value !== null);

        if ($this->social !== null) {
            $profile['social'] = $this->social->toArray();
        }

        return $profile;
    }
}
