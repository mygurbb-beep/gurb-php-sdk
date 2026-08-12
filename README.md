# Gurb PHP SDK

Official PHP SDK for the [Gurb](https://mygurb.com) community platform. It is the PHP port of
[`@gurb/server`](https://github.com/gurb/gurb-typescript-sdk) and behaves identically on the wire.

| Package | Runs in | Holds the API key | Use it for |
|---|---|---|---|
| `gurb/sdk` (this) | PHP 8.2+, server-side | yes | Reading your community's data; minting embed sessions |
| `@gurb/embed` (npm) | Browser | **never** | Rendering a community section inside your page |

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
`blogs`, `albums`, `members`. Lists return `Paginated`, which is iterable and countable and carries
`->items`, `->page`, `->limit`, `->total`, `->hasMore`.

The key is pinned to one community — there is no `$communityId` argument anywhere, and a key can
never read a community other than the one it was minted for.

## Embed a community section in your site

Three steps, and the middle one is the whole security model: **your backend mints the token, your
browser never sees the key.**

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
- **Read another community.** No method takes a `communityId`. The key is the scope.
- **Write anything except an embed session.** Members, posts and events are read-only here, so a
  stolen key cannot restructure or vandalise a community.

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
  `https://mygurb.com/api/v1` produces requests to routes that do not exist — and because unmatched
  `/api/*` paths on the platform fall through to root-mounted routers, they answer `400`/`401`
  rather than `404`, sending you off to debug auth for what is really a typo in a URL.
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

## Status

The typed client, the error mapping and the snippet renderer are implemented and tested. The two
backend endpoints they call — `POST /api/embed/sessions` and the token exchange behind `/embed/*` —
are being built separately; until they ship, the embed path is exercised against a stubbed transport.
