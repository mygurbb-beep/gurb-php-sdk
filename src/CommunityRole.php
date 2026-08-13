<?php

declare(strict_types=1);

namespace Gurb;

/**
 * A member's role inside ONE community.
 *
 * NOTE WHAT IS MISSING: there is no `SuperAdmin` case, and that omission is the
 * feature. SUPER_ADMIN is platform-level — it is not a community membership at
 * all — and no API call may ever grant it. Leaving it out of the enum means
 * there is no way to *ask* for it: `setRole($id, CommunityRole::SuperAdmin)`
 * does not compile, so the mistake cannot reach the wire and the server never
 * has to defend against it from this SDK. A string parameter would have made
 * `setRole($id, 'SUPER_ADMIN')` a plausible thing for someone to type.
 *
 * A backed enum rather than loose strings for the same reason `EmbedSection` is
 * one: a typo becomes an error where you wrote it, instead of a 400 you have to
 * read a response body to understand.
 *
 * The platform still caps every change at your key's own authority — a key
 * minted by a MODERATOR cannot produce an ADMIN. That check lives on the server,
 * because a client-side check is a suggestion, not a control.
 */
enum CommunityRole: string
{
    case Admin = 'ADMIN';
    case Moderator = 'MODERATOR';
    case Member = 'MEMBER';
}
