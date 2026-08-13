# Changelog

This package tracks [`@gurb/server`](https://github.com/mygurbb-beep/gurb-typescript-sdk)
version for version. The two SDKs are written against one route contract and are meant to be
interchangeable on the wire, so `gurb/sdk` **0.2.0** and `@gurb/server` **0.2.0** speak the same
API — same paths, same request bodies, same local guards, same error codes.

> **Known discrepancy.** The git tags in this repository read `1.0` and `1.1`; they predate the
> alignment above and name the same two releases documented here as 0.1.0 and 0.2.0. They should be
> re-cut as `0.1.0` and `0.2.0` before anything is published to Packagist — a `1.x` tag promises a
> stable API that neither SDK is offering yet, and it leaves "which PHP release matches
> `@gurb/server` 0.2.0?" with no answer.

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
