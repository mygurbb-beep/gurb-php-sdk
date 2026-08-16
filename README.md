# Gurb PHP SDK

Official PHP SDK for the [Gurb](https://mygurb.com) community platform. It is the PHP port of
[`@gurb/server`](https://github.com/gurb/gurb-typescript-sdk) and behaves identically on the wire.

| Package | Runs in | Holds the API key | Use it for |
|---|---|---|---|
| `gurb/sdk` (this) | PHP 8.2+, server-side | yes | Reading and managing one community; minting embed sessions |
| `@gurb/embed` (npm) | Browser | **never** | Rendering a community section inside your page |

Two clients, two credentials, and they are not interchangeable:

| Class | Credential | Scope |
|---|---|---|
| `GurbClient` | `gurb_<id>_<secret>` | Exactly one community |
| `GurbAdminClient` | `gurb_sa_<id>_<secret>` | The whole platform |

Each one **refuses the other's key at construction**, with a message naming what went wrong. See
[The admin/community split](#the-admincommunity-split).

There is no PHP equivalent of `@gurb/embed`, and there should not be: that package runs in the
visitor's browser, and PHP has finished running before the browser sees anything. What PHP owns is
the handoff — `EmbedSnippet` prints the `<script>` + `GurbEmbed.mount({...})` block with a freshly
minted token already in it.

## Install

```bash
composer require gurb/sdk
```

Requires PHP 8.2+ and `ext-json`. No HTTP library is required: the default transport is `ext-curl`,
and anything else can be injected (see [Bring your own HTTP client](#bring-your-own-http-client)).

## Read your community's data

```php
use Gurb\GurbClient;

$gurb = new GurbClient(getenv('GURB_API_KEY'));

$community = $gurb->community->get();
$feed      = $gurb->tweets->list(limit: 20);

foreach ($feed as $tweet) {
    echo $tweet->author->displayName, ': ', $tweet->content, "\n";
}
```

Available: `community->get()`, and `list(page:, limit:)` on `tweets`, `events` (plus `upcoming:`),
`blogs`, `albums`, `groups`, `consultants`, `projects`, `projects2`, `awards`, and `members` (plus
`role:`). Lists return `Paginated`, which is iterable and countable and carries `->items`, `->page`,
`->limit`, `->total`, `->hasMore`.

Three things about those sections that will otherwise look like bugs:

- **`projects2` is a parallel module, not a newer `projects`.** A community may run either or
  both, and nothing in a response tells you which. Read both and merge if you do not know — they
  publish the same `Project` shape. A client that reads only one returns an empty list for half
  the platform, and that looks like "no projects" rather than "wrong module".
- **`Consultant::$title` and `Award::$awardedAt` are always `null` today**, and deliberately so.
  A consultant row is an offered *service*, not a person, so there is no title column; `awards` is
  the *catalogue* of medals a community defines, and when one was granted is a property of the
  grant, not of the definition. Both stay in the shape so the contract need not change when a real
  source arrives. Do not substitute `createdAt` for `awardedAt` — that is when the medal was
  defined.
- **`groups` includes private groups**, flagged by `$group->isPrivate` rather than filtered out.
  Your key acts for the community, so its own private groups are its business; deciding what to
  render to end users is yours.

The key is pinned to one community — no method on `GurbClient` takes a `$communityId`, and a key can
never read a community other than the one it was minted for.

## Publish content

Every read section that can be written, can be written.

```php
$tweet = $gurb->tweets->create('مرحباً بالجميع');
$gurb->tweets->update($tweet->id, content: 'مرحباً بالجميع 👋');
$gurb->tweets->delete($tweet->id);

$gurb->events->create('Launch', 'التدشين', '2026-09-01T10:00:00Z', '2026-09-01T12:00:00Z');
$gurb->blogs->create('عنوان', '<p>المحتوى</p>');
$gurb->albums->create('رحلة الربيع');          // created EMPTY — see uploads below
$gurb->groups->create('نادي القراءة', 'وصف', false, 'https://cdn.example/icon.png');
$gurb->consultants->create('د. أحمد', 'https://cal.example/ahmed');
$gurb->projects->create('مشروع', 'وصف');
$gurb->awards->create('وسام المثابرة', 'trophy', '#E8B830');
$gurb->tasks->create($headingId, 'راجع الطلبات');
```

Comments and likes hang off the thing they belong to:

```php
$gurb->comments->create(CommentParent::Tweets, $tweetId, 'شكراً');
$gurb->likes->like(LikeParent::Blogs, $blogId);
$state = $gurb->likes->state(LikeParent::Tweets, $tweetId);   // ->liked, ->count
```

### The permissions differ per section, and that is on purpose

This SDK uses **the same permission Gurb's own web app checks** for each action — never a stricter
one and never a looser one. So the requirements are not uniform and cannot be made so:

| Section | Creating needs |
|---|---|
| tweets | `posts:manage` or `posts:create` |
| events | `calendar:manage` or `calendar:create` |
| blogs | `blogs:manage` or `blogs:create` |
| albums | `albums:manage` or `albums:create` |
| groups | `groups:manage` or `groups:create` |
| consultants | `consultants:manage` |
| projects | `projects:manage`, `projects:create` or `projects:moderate` |
| awards | `awards:manage` |
| tasks | `tasks:manage` |
| **comments, likes** | **nothing — membership is the gate** |

A community admin who grants `blogs:create` in the dashboard expects that grant to mean the same
thing through an integration. If this SDK demanded `blogs:manage` instead, the grant would silently
not work through the API and nobody could explain why.

**A plain member holds none of them.** In Gurb, `MEMBER` resolves to an empty permission set and
publishing is a capability an admin grants. So a key whose owner was never granted anything reads
everything and publishes nothing — that is correct behaviour, not a misconfiguration, and the first
403 you meet is probably this.

Comments and likes are the exception because they are what an ordinary member does. Being a member
is the whole requirement.

### Editing someone else's content

Update and delete check **authorship first**. Your key can always edit what it published, and needs
that section's `*:manage` or `*:moderate` for anyone else's. Expect 403s when operating on content
your key did not create — that is the web app's rule, not an SDK restriction.

### Feature gates are not permissions

Most sections sit behind a plan flag. A community whose plan lacks it answers
`403 FEATURE_NOT_AVAILABLE` on **every** call to that section, reads included. No permission grant
fixes that; it is an entitlement. `tweets` has no gate at all — that asymmetry is real.

### Uploads are not supported yet

Text and JSON only, everywhere. An album is created **empty**, a tweet carries no attachment, a blog
has no header image. Uploads need storage-budget accounting this surface does not implement, and
shipping them without it would let a community silently exceed the storage its plan pays for.
Members can still add images through the app.

Where an image is unavoidable — a group icon, an award icon — pass a URL or a preset key.

### Two shapes that will otherwise look like bugs

- **Projects write `name` and read `title`.** Reading a project and posting it straight back fails:
  `title` sets nothing and `name` is missing. Map it yourself.
- **`POST /tasks` ignores `status`.** Every task starts `PENDING`; the field is not accepted rather
  than accepted and dropped. The date field is `dueAt`, not `dueDate`.

## Customize the community

```php
$settings = $gurb->community->getSettings();
$gurb->community->updateSettings(['directMessagesEnabled' => false]);

$gurb->community->updateBranding(['primaryColor' => '#093431']);
$gurb->community->updateLegal(terms: '...', privacyPolicy: '...');

$gurb->sidebar->get();
$gurb->sidebar->update($items);      // PUT — replaces the item list
$gurb->sidebar->reset();

$gurb->advertisements->active();     // member-facing; NOT paginated
$gurb->advertisements->list();       // admin view; paginated
```

**Send only the keys you mean to change.** Never read the settings blob and post it back. The
server merges deeply for `pageVisibility` **only**; every other key you include replaces its
counterpart wholesale, and `homeWidgets` has no deep merge at all. A round-trip therefore quietly
overwrites anything added between your read and your write.

Settings accept a **fixed list of seven keys** and reject anything else rather than ignoring it.

## Manage members, roles and permissions

```php
use Gurb\{CommunityRole, Permission};

$gurb->members->setRole('mem_1', CommunityRole::Moderator);

$permissions = $gurb->members->getPermissions('mem_1');
$permissions->role;        // 'MODERATOR'
$permissions->effective;   // everything in force
$permissions->granted;     // added to this member specifically
$permissions->revoked;     // taken away from this member specifically
$permissions->can(Permission::MODERATE_CONTENT);   // true

$gurb->members->remove('mem_1');   // leaves the community, keeps the account
```

Grants and revokes go in **one call**, never two:

```php
$gurb->members->updatePermissions(
    'mem_1',
    grant:  [Permission::EDIT_ANY_POST],
    revoke: [Permission::DELETE_ANY_POST],
);
```

Two calls could not be made atomic from out here: between them the member holds a state you never
asked for — the grant applied and the revoke not — and if the second call fails, times out, or the
process is killed mid-deploy, they stay there. Sending the same permission in both lists throws
locally, because there is no correct guess at what was meant.

`CommunityRole` is an enum of `Admin`, `Moderator`, `Member`. **There is no `SuperAdmin` case** —
see [what you cannot do](#the-admincommunity-split).

`Permission` is a class of **string constants**, deliberately not an enum. The platform's catalogue
grows, and using a permission added last week must not require upgrading this package first — so any
string is accepted and `Permission::KNOWN_PERMISSIONS` is reference material, not a gate.

## Sync many members at once

```php
use Gurb\Input\BulkMemberInput;
use Gurb\Resource\MembersResource;

$result = $gurb->members->bulkUpsert([
    new BulkMemberInput('shop-1', 'Sara', 'sara@example.com'),
    new BulkMemberInput('shop-2', 'Omar', role: CommunityRole::Moderator),
]);

$result->created;         // list<Member> — externalUserId was new
$result->updated;         // list<Member> — matched an existing member
$result->failed;          // list<BulkFailure> — rows the server refused
$result->total;
```

Rows are matched on `externalUserId`, so **re-sending the same list is a sync, not a second import**.
That makes this safe to run nightly against your whole user table, and safe to retry after a
timeout — you cannot tell a timeout that landed from one that did not.

**Individual row failures do not throw.** One bad email address in a list of 500 must not discard the
other 499, so failures are a return value you inspect. An empty `failed` is the only proof that
everything landed:

```php
foreach ($result->failed as $failure) {
    // ->index is the position in the array YOU sent, so it maps back to its input
    $log->warning("row {$failure->index} ({$failure->externalUserId}): {$failure->message}");
}
```

Three things are refused locally, before anything is sent: an empty list, more than
`MembersResource::BULK_MEMBER_LIMIT` (500) rows, and a duplicate `externalUserId` inside one batch
(the message names the offending id). Chunk longer lists:

```php
foreach (array_chunk($rows, MembersResource::BULK_MEMBER_LIMIT) as $chunk) {
    $gurb->members->bulkUpsert($chunk);
}
```

## Ask for a new community

A community key can never create a community outright. It files a request that a platform admin
approves or rejects — the same gate the dashboard uses:

```php
use Gurb\{CommunityRequestStatus};
use Gurb\Input\CreateCommunityInput;

$request = $gurb->requestCommunityCreation(new CreateCommunityInput(
    name: 'نادي القراءة',
    slug: 'book-club',
));

$request->isPending();     // true — this is a request, not a community
$request->communityId;     // null until approved
```

`CreateCommunityInput` carries **a name and a slug, and nothing else** — the same two fields
`$admin->communities->create()` takes, and with the same rules. `description`, `type` and
`ownerUserId` were removed in 0.4.0 rather than accepted and ignored. `$request->description`
still exists on the way *back*, because requests filed before that release carry one.

Do not assume success. Poll, or subscribe to the `community.approved` webhook:

```php
$request = $gurb->communityRequests->get($request->id);

if ($request->isApproved()) { $id = $request->communityId; }
if ($request->isRejected()) { echo $request->rejectionReason; }

$gurb->communityRequests->list(CommunityRequestStatus::Pending);
```

Slugs are unique platform-wide, so "already taken" is a normal `VALIDATION_ERROR` to show as a form
error, not an exceptional condition.

## Platform administration

Everything here needs a **super-admin key** and a different class:

```php
use Gurb\{ApiKeyScope, GurbAdminClient};

$admin = new GurbAdminClient(getenv('GURB_ADMIN_KEY'));

// Communities
$admin->communities->list(search: 'club');
$admin->communities->create(name: 'مجتمع القراءة', slug: 'book-club');   // two fields, both required

// The approval queue
$admin->communityRequests->list(CommunityRequestStatus::Pending);
$admin->communityRequests->get('creq_1');
$admin->communityRequests->approve('creq_1');            // creates the community
$admin->communityRequests->reject('creq_1', 'Slug is too generic');   // reason required

// API keys
$admin->apiKeys->list(communityId: 'cmt_1');
$minted = $admin->apiKeys->create('cmt_1', 'zapier-prod');   // defaults to [read]
$admin->apiKeys->revoke($minted->id);
```

### Who owns the community you create

By default the community is founded by **whoever the key belongs to** — and a platform key can only
be minted by a Gurb super admin, so by default that is the platform admin, not you. Communities
created that way belong to someone else, and nobody notices until they ask why.

Name yourself, by e-mail:

```php
$community = $admin->communities->create(
    name: 'نادي القراءة',
    slug: 'book-club',
    ownerEmail: 'ahmed@corp.com',       // ← this account founds it
);
```

That account becomes the community's sole `COMMUNITY_ADMIN`. If it has no Gurb account yet, one is
created — pass `ownerFirstName:` and `ownerFamilyName:` if you have them, or the local part of the
address is used.

An **existing** account is reused and never modified: no password change, no name overwrite, no role
anywhere except inside the community it is about to found. Naming an address that already belongs to
somebody hands them a community; it cannot take their account over.

Ask the platform which account your key acts for before you create anything:

```php
$me = $admin->me();

if ($me->actsForIssuer) {
    throw new RuntimeException('This key names no subject — pass ownerEmail, or ask for a new key.');
}
echo $me->effectiveOwner()->email;
```

`$owner` (a full `ExternalOwner` with a registered identity provider) does the same job with a
provider assertion behind it. Send one or the other — both together is refused before any request,
because they answer the same question two ways and there is no useful behaviour for the
contradiction.

### Creating a community takes exactly two fields

`create(name:, slug:)` plus the optional owner above, and nothing else. There is no `$description`
and no `$type`, because the endpoint does not honour them: **visibility is decided server-side
(PRIVATE).** Accepting those and
dropping them would be worse than not offering them — a caller who passes `type: 'PUBLIC'`, gets a
private community and no error has been lied to by an API that looked like it was listening. Adjust
the rest afterwards through the dashboard or the community settings endpoints.

- **`name`** is the human-facing name, usually Arabic. Required, trimmed before sending, at most
  **100 characters** — counted as characters, not bytes, so a 100-character Arabic name fits.
- **`slug`** is the latin identifier in the URL. Lowercase letters, digits and hyphens only, **3–50
  characters**, no leading or trailing hyphen, no consecutive hyphens.

The slug is checked **before any HTTP call**, and the message names the rule you broke rather than
saying "invalid slug" — including the common one:

```php
$admin->communities->create(name: 'نادي', slug: 'Book-Club');
// GurbApiException: Community slugs are lowercase: "Book-Club" is not valid,
// but "book-club" would be. …   (code VALIDATION_ERROR, status 0)
```

Status `0` is the tell: nothing was sent, so nothing was created. Uniqueness is *not* checked
locally — a client cannot know it — so a taken slug still arrives as a `400 VALIDATION_ERROR` from
the server, and that is a normal form error rather than an exceptional condition.

`$minted->key` is the plaintext key and it is shown **exactly once**. There is no read-back route, by
design: if the secret could be fetched again, read access to your admin surface would be equivalent
to holding every key it ever issued. Hand it to its owner now, or discard it and mint another.

A rejection reason is a required parameter and is validated **before** any HTTP call — the requester
is shown it, and "rejected" with no explanation generates a support ticket every time.

Approving an already-decided request fails rather than creating a second community: the decision is
what is idempotent, not the click.

### The admin/community split

`GurbAdminClient` is a separate class rather than extra methods on `GurbClient`, and that is the
security model rather than a naming preference.

- **A community key cannot reach platform administration — not even by autocomplete.** You cannot
  arrive at `communities->create()` from a client you built to render a feed, because that client
  does not have it. The failure mode this prevents is ordinary: a developer needs one admin
  operation for a migration script, passes the admin key to the client the app already builds, and
  now the request handler that renders a tweet list holds a credential that can mint keys for every
  community on the platform.
- **Each client rejects the other's key at construction**, with a message naming the mistake rather
  than a generic "malformed key". The shapes are mutually exclusive by construction — `sa` is not
  twelve hex characters — so this is not a matter of check order.
- **`GurbAdminClient` has no `tweets`, no `members`, no `createEmbedSession()`.** A super-admin key
  has no community context; it is not a member of anything. To read a community, mint a community
  key for it and build a `GurbClient`. The extra step is the audit trail.
- **No API call can grant `SUPER_ADMIN`.** It is not in the `CommunityRole` enum, so there is no way
  to ask for it — `setRole($id, CommunityRole::SuperAdmin)` does not compile.
- **A minted key's secret can never be read back.** `apiKeys->list()` returns summaries with no
  `key` property at all.
- **Revocation is one-way.** There is no un-revoke, so "is this key still live?" has exactly one
  answer.

⚠️ Treat the super-admin key like a database password: one holder, injected from a secret store,
never in a repository, rotated when staff change. If you are unsure whether a script needs it, it
does not.

## Embed the community in your site

Three steps, and the middle one is the whole security model: **your backend mints the token, your
browser never sees the key.**

### The whole community, or one section

`EmbedSection::Community` frames **the community page itself** — its navigation and every section
the plan enables — exactly as a member sees it after signing in. They land where a normal sign-in
puts them and move around inside the frame. Reach for it when the frame *is* the page.

The other cases (`Tweets`, `Events`, `Blogs`, `Albums`) are chrome-less panes meant to sit inside
your own layout: your header, your navigation, one Gurb panel among your content. They have no menu
and cannot navigate anywhere.

The trade is real. A whole community brings its own scroll height, its own internal routing — your
back button will not follow it — and links that may try to leave the frame. A pane brings none of
that. Heights default accordingly: 900px for a community, 600px for a pane.

### Three ways to render it

```php
$snippet = new EmbedSnippet('https://mygurb.com');

// (a) Loader script: auto-resizes once the frame reports its height.
echo $snippet->render('gurb', 'my-club', EmbedSection::Community, $token);

// (b) Loader script, token fetched from YOUR endpoint after page load.
//     Use when the page may be cached longer than the token lives (~120s).
echo $snippet->renderAsync('gurb', 'my-club', EmbedSection::Community, '/api/gurb-token');

// (c) A plain iframe. No script, no CDN, nothing to load.
echo $snippet->iframe(EmbedSection::Community, $token);
```

`(c)` is the one to use when a third-party script on your critical path is unwelcome, or your CSP
forbids one. What you give up is the loader's auto-resize, so pick a height that suits your layout —
the frame will not grow by itself.

All three put the token in the **URL fragment**, never a query string. A fragment is never
transmitted to a server, so the token stays out of Gurb's access logs, out of yours if you proxy,
and out of the `Referer` header of every outbound link the framed page renders. It is a live
credential; that is the difference between a short-lived secret and a logged one.

### Gurb must allow your origin to frame it

Framing is refused unless the Gurb deployment sends `Content-Security-Policy: frame-ancestors`
covering your site on the `/embed` path. `X-Frame-Options` cannot express a list of origins,
`ALLOW-FROM` is dead in current browsers, and sending both headers makes browsers ignore the CSP
list entirely — so it must be CSP, and `X-Frame-Options` must be absent on that path. If the frame
stays blank with a console message about `X-Frame-Options`, that is a server configuration on
Gurb's side, not a bug in your integration.

**1. Your backend mints a token for one of your own logged-in users:**

```php
// Your app, your auth. This user is already logged in to YOUR site.
$session = $gurb->createEmbedSession(
    externalUserId: (string) $currentUser->id,   // your id for this human, stable forever
    displayName:    $currentUser->name,
);
```

First time Gurb sees an `externalUserId`, it creates a user and adds them to your community as a
`MEMBER`. After that the same id always resolves to the same person — which is why it must be a
stable internal id, never an email or a username someone can change.

**2. Your page mounts the frame:**

```php
echo $gurb->embedSnippet()->render(
    targetId: 'community',
    slug:     'my-community',
    section:  Gurb\EmbedSection::Tweets,   // one section per frame
    token:    $session->token,
);
```

If your pages are cached for longer than a token lives (~120 seconds), print a fetch callback
instead and keep the token out of the markup entirely:

```php
echo $gurb->embedSnippet()->renderAsync('community', 'my-community', EmbedSection::Tweets, '/api/gurb-token');
```

…where `/api/gurb-token` is a route in *your* app that runs step 1 behind your own auth.

Rendering the iframe yourself instead of using the snippet? Use `buildEmbedUrl()` — it puts the
token in the URL **fragment** (`#t=`), which is never sent to a server and so never lands in nginx
access logs or `Referer` headers:

```php
$url = $gurb->buildEmbedUrl('my-community', EmbedSection::Tweets, $session->token);
// https://mygurb.com/embed/my-community/tweets#t=...
```

**3. There is no step three.** Permissions, feature gates and moderation all apply inside the frame
exactly as they do on mygurb.com, because the embedded user is an ordinary member.

### What you cannot do, on purpose

- **Pass a role.** `createEmbedSession()` has no `$role` parameter and the request body has no field
  for one. Every embedded user is a `MEMBER`. A leaked key can create fake members in one
  community; it can never manufacture a moderator.
- **Log in as an existing Gurb account.** Embedded identities are separate. Otherwise any host site
  could claim someone's email and take over their account.
- **Send an API key to the browser.** `EmbedSnippet` throws if the "token" you hand it starts with
  `gurb_`, because in PHP the same process holds the key *and* writes the HTML.
- **Read another community.** No method on `GurbClient` takes a `communityId`. The key is the scope.
- **Post content.** Tweets, events, blogs and albums are read-only through this SDK. Membership is
  writable (that is what most integrations are for); content is not.
- **Escalate anyone off this community.** `setRole()` takes a `CommunityRole`, and that enum has no
  platform role in it. The server additionally caps every change at your key's own authority — a key
  minted by a MODERATOR cannot produce an ADMIN — and evaluates it against the live membership of
  whoever minted the key, so a key stops working the moment its owner is demoted.

## Errors

Everything throws `GurbApiException` with a stable `code()`:

```php
use Gurb\{GurbApiException, GurbErrorCode};

try {
    $gurb->blogs->list();
} catch (GurbApiException $e) {
    if ($e->code() === GurbErrorCode::FEATURE_NOT_AVAILABLE) {
        // blogs are not on this community's plan
    }
    if ($e->isRetryable()) {
        // NETWORK_ERROR, RATE_LIMITED, or 5xx — everything else is your bug
    }
    error_log($e->getMessage() . ' request=' . $e->requestId());
}
```

`code()`, `status()`, `requestId()` and `isRetryable()` are methods rather than properties because
PHP's built-in `Exception` already owns a `$code` property and a subclass cannot redeclare it. The
inherited `getCode()` carries the HTTP status, which is what generic log handlers look for.

Transport failures (DNS, refused connection, TLS, timeout) arrive as the same exception with
`code() === 'NETWORK_ERROR'` and `status() === 0`, so there is exactly one thing to catch.

**Arguments this SDK refuses before sending anything** — a conflicting grant/revoke pair, an empty
change set, an oversized or duplicate-carrying bulk list, a rejection with no reason — throw the same
exception with `code() === 'VALIDATION_ERROR'` and `status() === 0`. Zero is the tell: it means no
server ever saw the request, which is what distinguishes a local guard from the `422` you would have
got had we sent it. Every local guard produces that identical shape on purpose, so a caller never has
to handle two kinds of failure for what is one mistake.

Messages are English by design — your app owns translation. Unrecognised backend error strings never
leak into `code()`, so a `match` on it will not break the day the backend adds one; the original
text stays in `getMessage()`.

## Bring your own HTTP client

`Gurb\Http\HttpClient` is a one-method interface. Inject an implementation to reuse your app's proxy
configuration, retry middleware or request logging:

```php
use Gurb\Http\Psr18HttpClient;

$gurb = new GurbClient(
    apiKey:     getenv('GURB_API_KEY'),
    httpClient: new Psr18HttpClient($guzzle, $psr17Factory, $psr17Factory),
);
```

PSR-18 is supported but not required — the interfaces are a `suggest`, not a `require`, so installing
this SDK does not drag an HTTP stack into your dependency tree. If your logging middleware dumps
request headers, exclude `X-Api-Key`.

## Configuration notes

- **`baseUrl` is an origin with no `/api` suffix.** The client appends `api/` itself. Passing
  `https://mygurb.com/api/v1` produces requests to routes that do not exist, and the response is not
  always a clean `404` — so a typo here can present as an authentication problem and send you off
  debugging credentials that were never wrong.
- **`timeoutMs`** is the whole-request budget, default 15s.

## Development

```bash
composer install
composer test
```

No PHP locally? The suite runs in a container:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit
```

Tests never touch the network: they inject `StubHttpClient`, which is a real transport rather than a
mock, so the code under test takes exactly the path it takes in production.

That proves the SDK builds the right request; it cannot prove a server would accept it. The other
half lives in [`contract/`](contract/README.md), which drives this SDK over **real HTTP** against the
TypeScript SDK's reference mock and asserts the verb, URL, headers, body bytes and status of every
endpoint. Run it whenever you touch a path or a payload.

## Status

The typed clients, the error mapping and the snippet renderer are implemented and tested. The backend
endpoints they call are being built separately; until they ship, the SDK is exercised against a
stubbed transport in the unit suite and against the reference mock server
(`examples/host-demo/mock-gurb.mjs` in the TypeScript SDK repo) over real HTTP — see
[`contract/`](contract/README.md). Release history is in [CHANGELOG.md](CHANGELOG.md); this package
tracks `@gurb/server` version for version.

This package behaves identically on the wire to `@gurb/server`. Both were written against the same
route contract, and the shared pieces — the two key patterns, the `X-Api-Key` header, the
`{ success, data }` unwrapping, the 500-row bulk limit — are deliberately duplicated character for
character rather than approximated, so the two SDKs cannot disagree about what is valid.
