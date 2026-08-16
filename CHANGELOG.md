# Changelog

This package tracks [`@gurb/server`](https://github.com/mygurbb-beep/gurb-typescript-sdk)
version for version. The two SDKs are written against one route contract and are meant to be
interchangeable on the wire, so `gurb/sdk` **0.3.0** and `@gurb/server` **0.3.0** speak the same
API — same paths, same request bodies, same local guards, same error codes.

0.4.0 matches `@gurb/server` 0.4.0 on the wire: both community-creation endpoints send exactly
`{"name":…,"slug":…}`, and both SDKs validate the slug locally before sending. The two differ
only in call-site shape, which is language idiom rather than contract —
`create(name:, slug:)` in PHP against `create({ name, slug })` in TypeScript. PHP additionally
reports *which* slug rule failed instead of one combined message, and counts the name limit in
code points rather than UTF-16 units.

> **Known discrepancy — do not publish until this is resolved.** The git tags in this repository
> read `1.0`, `1.1` and `1.2`, and they do not line up with the releases documented below.
>
> - `1.0` and `1.1` predate the alignment above and name the releases documented here as 0.1.0
>   and 0.2.0. A `1.x` tag also promises a stable API that neither SDK is offering yet.
> - **`1.2` points at commit `b9a945b`, the same commit as `1.0`.** That commit is not an ancestor
>   of the current branch and predates every release described here — it has no admin client, no
>   permissions, no bulk membership and none of the 0.3.0 content sections. Anyone resolving
>   `gurb/sdk:1.2` from Packagist would get code older than `1.1`.
>
> The tags want re-cutting as `0.1.0`, `0.2.0` and `0.3.0` against the commits they actually
> describe, before anything is published.

## 0.5.0

Matches `@gurb/server` 0.5.0 on the wire. The server side of bulk membership and
embed sessions, and a way to create the account that owns a community.

### Added

- **`AdminCommunitiesResource::create()` takes an optional third argument,
  `?ExternalOwner $owner`.** A person your identity provider vouches for founds
  the community, instead of the credential that made the call.

  ```php
  $community = $admin->communities->create(
      name: 'نادي القراءة',
      slug: 'book-club',
      owner: new ExternalOwner(
          provider: 'okta',
          externalUserId: $oktaSub,
          email: $email,
          emailVerified: true,
      ),
  );

  if ($community->owner?->linkedExistingAccount()) {
      // An account that already had a history is now reachable through Okta.
      // Gurb has e-mailed its owner. If you did not expect this, investigate.
  }
  ```

  `ExternalOwner::$emailVerified` has **no default**, deliberately: it decides
  whether an incoming identity may join an account that already exists, and a
  default would let a caller reach that branch without having thought about it.
  Say `false` if unsure — the cost is a refusal, not a takeover.

- `Gurb\Model\CommunityOwner`, reachable as `Community::$owner`, with
  `linkedExistingAccount()` and `createdNewAccount()`.

- **`MembersResource::bulkUpsert()` and `GurbClient::createEmbedSession()` now
  have endpoints behind them.** Both were published in 0.2.0 against a server
  that did not implement them. No call-site changes — they simply stop 404ing.

### Same rules as the TypeScript SDK

Identity binds on **(namespace, externalUserId)**, never on e-mail. Only
`create(owner:)` can attach to a pre-existing Gurb account, and only under four
conditions the server enforces; `bulkUpsert()` and `createEmbedSession()` carry
no provider and never can. Banned members are refused; members who LEFT are
re-admitted (and admin removal also records `LEFT`, so ban rather than remove
when it must stick); `role` applies on creation only; bulk imports fire no
webhook and send no notifications. The full list is in `@gurb/server`'s
CHANGELOG for 0.5.0 — the two SDKs describe one contract and neither is
authoritative over the other.

## 0.4.0

Community creation narrowed to what the endpoint actually honours.

### Changed

- **BREAKING — `AdminCommunitiesResource::create()` has a new signature.**

  ```php
  // before
  $admin->communities->create(new CreateCommunityInput(name: 'مجتمع', slug: 'club', type: 'PUBLIC'));
  // after
  $admin->communities->create(name: 'مجتمع', slug: 'club');
  ```

  Two arguments, both required, and the request body is now exactly
  `{"name":…,"slug":…}`. `POST /api/admin/sdk/communities` decides visibility itself
  (PRIVATE) and takes the owner from the credential, so `description`, `type` and
  `ownerUserId` are no longer sent — and are no longer accepted, rather than accepted
  and discarded. A parameter that is silently dropped is worse than one that does not
  exist: a caller who passes `type: 'PUBLIC'`, gets a private community and no error
  has been lied to by an API that looked like it was listening.

  There is no deprecation period because there is no compatible middle: the old
  signature took one object, the new one takes two scalars, so every call site is a
  compile-time failure rather than a silent behaviour change. That is the intended
  outcome.

### Added

- Client-side validation of both fields, raising the usual local-validation failure
  (`VALIDATION_ERROR`, status `0`, nothing sent) with a message that **names the rule**:
  - `slug` — lowercase letters, digits and hyphens only; 3–50 characters; no leading or
    trailing hyphen; no consecutive hyphens. An uppercase slug is told the lowercase form
    it should have used, because that is the mistake people actually make.
  - `name` — required, not whitespace-only, valid UTF-8, at most 100 characters **counted
    as characters and not bytes**. `strlen('نادي')` is 8, not 4; measuring bytes would
    reject an ordinary 60-character Arabic name for being "over 100", a limit that
    misfires only in the language the product is used in.
- `AdminCommunitiesResource::SLUG_MIN_LENGTH`, `SLUG_MAX_LENGTH` and `NAME_MAX_LENGTH`,
  so callers can pre-validate a form against the same numbers instead of copying them.

### Fixed

- **Malformed UTF-8 in any request body escaped the SDK as a raw `JsonException`.**
  `Internal\Requester` encodes with `JSON_THROW_ON_ERROR` and nothing caught it, so a
  latin-1 display name pulled from an old table into a 500-row `bulkUpsert` took the batch
  down with an exception outside the one type this library asks you to catch. It is now a
  `GurbApiException` — `VALIDATION_ERROR`, status `0`, nothing sent — with a message naming
  the usual cause. Found while mutation-testing the new name validation, and it predates
  this release; every write path was affected, not just community creation.

- **BREAKING — `CreateCommunityInput` lost `description`, `type` and `ownerUserId`**, and now
  carries a name and a slug only. It feeds `$gurb->requestCommunityCreation()`, so
  `POST /api/sdk/community-requests` narrowed with the direct-creation path rather than
  drifting apart from it. Both community-creation endpoints now take the same two fields.

  `CommunityRequest::$description` is **kept** on the way back. A response field is not a
  request field: requests filed before this release carry a description and the server
  still returns it, so removing it from the model would discard data that exists.

### Known risk

The slug rules are a client-side copy of a server-side rule, and a copy is wrong the moment
the original relaxes. If the platform ever widens what a slug may contain — underscores,
unicode, shorter minimums — this SDK will refuse slugs the platform would accept, and no
server-side change can fix it. Widen it here in the same release. The trade was taken
because a URL grammar changes about as often as URLs do, and the alternative is a
round-trip to learn you typed a capital letter.

## 0.3.0

Every content section the platform serves.

### Added

- `$gurb->groups`, `$gurb->consultants`, `$gurb->projects`, `$gurb->projects2` and
  `$gurb->awards`, alongside the existing `blogs` listing — eleven sections in total,
  matching the backend's public `/api/sdk` surface. All paginate with
  `list(page:, limit:)` and return `Paginated` exactly as the older sections do.
- Value objects `Group`, `Consultant`, `Project` and `Award`.
- `Gurb\Resource\Projects2Resource` beside `ProjectsResource`. **`projects2` is a
  parallel module, not a newer version** — a community may run either or both, and
  nothing in a response says which. Both endpoints are exposed rather than the SDK
  guessing, because a client that silently read only one would return an empty list
  for half the platform and it would look like "no projects" rather than "wrong
  module". If you do not know which a community uses, read both and merge; they
  publish the same `Project` shape.

### Notes on two fields that are always null

- **`Consultant::$title`** has no backing column. The underlying table models an
  offered *service*, not a person — which is also why `specialties` comes from a
  relation rather than a field. The field stays in the shape so the contract need not
  change when a title column arrives. This SDK will not invent a source for it:
  falling back to `displayName` would produce a value that looks authored and is not.
- **`Award::$awardedAt`** is null because `awards` is the *catalogue* of medals a
  community defines. When a medal was granted belongs to the grant
  (`award_recipients`), not to the definition. Real timestamps need a separate
  `awards/{id}/recipients` resource, which would publish member identities and so was
  not added silently.

Both decode to `null` and never to `''`, and there are tests pinning that: `""` reads
as "there is a value and it is blank" where `null` reads as "there is nothing here",
and a consumer checking `!== null` branches differently on each.

`Group::$isPrivate` is published as a field rather than filtered on: an SDK caller acts
for the community and may legitimately list its private groups. Filtering would have
made the field a constant `false` — and would have handed back half a list with nothing
in the response to say so. Deciding what to render is the caller's job.

### Changed

- `Internal\Decode::bool()` has its first caller (`Group::$isPrivate`); it was
  previously unreferenced. It defaults to `true` there, because every fallback in this
  SDK errs safely and for visibility "safe" means closed.

## 0.2.0

Administration, permissions and bulk membership.

### Added

- **`GurbAdminClient`** — platform administration behind a separate credential
  (`gurb_sa_<id>_<secret>`). Create communities, decide on requests, mint and revoke
  community keys. Deliberately a separate class rather than extra methods on `GurbClient`:
  `communities->create()` should not be something you reach by autocompleting from a client
  you built for reading a feed.
- **Community creation by approval** — `GurbClient::requestCommunityCreation()` and
  `CommunityResource::requestCreation()` file a request; a platform admin approves or rejects it.
  A rejection reason is required. `CommunityRequestsResource` reads back the verdict.
- **Per-member permissions** — `members->getPermissions()`, `members->updatePermissions()`
  (grants and revocations in one request), `members->setRole()`, `members->remove()`.
- **Bulk membership** — `members->bulkUpsert()`, matched on `externalUserId` so a re-run is
  a sync rather than a duplicate import. Row failures are reported in `BulkMemberResult::$failed`,
  not thrown. `MembersResource::BULK_MEMBER_LIMIT` is public so callers can chunk against it.
- New value types: `CommunityRequest`, `MemberPermissions`, `ApiKeySummary`, `ApiKeyWithSecret`,
  `BulkFailure`, `BulkMemberResult`.
- New inputs: `CreateCommunityInput`, `BulkMemberInput`.
- New enums: `CommunityRole` (no `SuperAdmin` case, so no API call can ask for a platform role),
  `CommunityRequestStatus`, `ApiKeyScope`. `Permission` is deliberately a class of string
  constants rather than an enum — the platform's catalogue grows, and using a permission added
  last week must not require upgrading this package first.
- `GurbApiException::localValidation()`, so every guard that fires before an HTTP call produces
  one identical shape: `VALIDATION_ERROR`, status `0`, not retryable.
- `LICENSE` (MIT).

### Changed

- The two credential shapes are now mutually exclusive and each client rejects the other's key
  **at construction**, with a message naming the mistake rather than a generic "malformed key".
  `ApiKey::assertValid()` gained `assertValidAdmin()` beside it; the single pattern of 0.1.0 became
  two that cannot both match one string.
- `Internal\Requester` carries `PATCH` and `DELETE` alongside `GET` and `POST`. Nothing else about
  it forked: the same header, the same error mapping, the same envelope unwrapping. A verb is not
  a reason to duplicate a transport.
- `Internal\Requester` now also **owns the choice of default transport**, instead of each client
  constructing its own. That mirrors the TypeScript SDK, where `Transport` owns both
  `options.fetch ?? globalThis.fetch` and the check that one exists. Two clients picking their own
  default is how the community and admin surfaces start behaving differently — and a difference in
  how a request is authenticated is a security difference, not a style difference.
- `GurbClient` no longer keeps its own copy of the base URL; `buildEmbedUrl()` and
  `embedSnippet()` read it from the requester, so a trailing slash is normalised in one place.

### Fixed

- **A missing `ext-curl` escaped the SDK as a raw `TransportException`.** `CurlHttpClient` was
  constructed by the client itself, before any `Requester` existed to catch it, so an app on a
  machine without curl met an uncaught `RuntimeException` — despite `TransportException` being
  documented as never escaping and the README promising "exactly one thing to catch". It is now a
  `GurbApiException` with `code() === 'NETWORK_ERROR'` and `status() === 0`, which is the answer
  the TypeScript SDK gives when there is no `fetch`.
- **A whitespace-only response body decoded to an empty payload instead of failing.** The
  emptiness check trimmed first, so a blank page from a proxy or a WAF produced a `Community` with
  an empty id and a `PRIVATE` visibility it had never asserted. The TypeScript SDK reads
  `text.length > 0 ? JSON.parse(text) : {}` — `""` is an empty payload and `"  "` is a parse
  failure — and PHP now agrees. A truly empty body is still fine, which is what a `DELETE` returns.

## 0.1.0

Initial release: typed community client and embed handoff.

- `GurbClient` with `community->get()` and paginated `tweets`, `events`, `blogs`, `albums`,
  `members`. No method takes a `$communityId` — the key is the scope.
- `Paginated`, iterable and countable, over the
  `{ items, page, limit, total, hasMore }` envelope.
- `createEmbedSession()` and `buildEmbedUrl()`, which puts the single-use token in the URL
  **fragment** so it never reaches an access log or a `Referer` header. There is no `$role`
  parameter and no field for one on the wire: a leaked key can create fake members, never
  moderators.
- `Embed\EmbedSnippet` — the PHP half of `@gurb/embed`, printing the `<script>` and
  `GurbEmbed.mount({...})` block. It refuses to render anything starting with `gurb_`, and encodes
  with `JSON_HEX_TAG`/`HEX_AMP`/`HEX_APOS`/`HEX_QUOT` so a slug or token cannot close the script
  block and inject markup. (The TypeScript repo shipped the equivalent XSS in its example mock and
  fixed it in 0.2.0; this SDK never had the defect.)
- `GurbApiException` with stable `code()`, `status()`, `requestId()` and `isRetryable()`.
  Unrecognised backend error strings never become a `code()`.
- `Http\HttpClient` as a one-method interface, with `CurlHttpClient` by default and
  `Psr18HttpClient` as an optional adapter — so installing this SDK drags no HTTP stack into your
  dependency tree.
