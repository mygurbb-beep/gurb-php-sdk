<?php

declare(strict_types=1);

namespace Gurb;

use Gurb\Embed\EmbedSnippet;
use Gurb\Http\HttpClient;
use Gurb\Input\CreateCommunityInput;
use Gurb\Internal\Requester;
use Gurb\Model\CommunityRequest;
use Gurb\Model\EmbedSession;
use Gurb\Resource\AdvertisementsResource;
use Gurb\Resource\AlbumsResource;
use Gurb\Resource\AwardsResource;
use Gurb\Resource\BlogsResource;
use Gurb\Resource\CommentsResource;
use Gurb\Resource\CommunityRequestsResource;
use Gurb\Resource\CommunityResource;
use Gurb\Resource\ConsultantsResource;
use Gurb\Resource\EventsResource;
use Gurb\Resource\GroupsResource;
use Gurb\Resource\LikesResource;
use Gurb\Resource\MembersResource;
use Gurb\Resource\Projects2Resource;
use Gurb\Resource\ProjectsResource;
use Gurb\Resource\SidebarResource;
use Gurb\Resource\TasksResource;
use Gurb\Resource\TweetsResource;

/**
 * Server-side SDK for the Gurb community platform, scoped to ONE community.
 *
 * ⚠️  This object holds your secret API key. Keep it in an environment variable,
 * never in a template variable, a JS bundle, or a JSON response to a browser.
 *
 * Platform administration lives on `GurbAdminClient` and needs a different
 * credential. This class refuses a super-admin key at construction rather than
 * quietly accepting more authority than it needs — see ApiKey::assertValid().
 */
final class GurbClient
{
    private const DEFAULT_BASE_URL = 'https://mygurb.com';
    private const DEFAULT_TIMEOUT_MS = 15_000;

    private readonly Requester $requester;

    public readonly CommunityResource $community;
    public readonly CommunityRequestsResource $communityRequests;
    public readonly TweetsResource $tweets;
    public readonly EventsResource $events;
    public readonly BlogsResource $blogs;
    public readonly AlbumsResource $albums;
    public readonly GroupsResource $groups;
    public readonly ConsultantsResource $consultants;
    public readonly ProjectsResource $projects;
    /** The parallel projects module — not a newer `projects`. See Projects2Resource. */
    public readonly Projects2Resource $projects2;
    public readonly AwardsResource $awards;
    public readonly TasksResource $tasks;
    /** Comments on tweets, blogs, albums and events — and the likes ON comments. */
    public readonly CommentsResource $comments;
    /** Likes on tweets, blogs and albums. There is no event like; see LikeParent. */
    public readonly LikesResource $likes;
    /** The navigation menu. Gated on the homepage-widgets plan feature, reads included. */
    public readonly SidebarResource $sidebar;
    /** Ad placements. Gated on the ads-management plan feature, reads included. */
    public readonly AdvertisementsResource $advertisements;
    public readonly MembersResource $members;

    /**
     * @param string $apiKey  `gurb_<id>_<secret>`, from your community dashboard.
     * @param string $baseUrl Origin only — NO `/api` suffix. The client appends
     *                        `api/` itself, matching the web app's own helper.
     *                        Passing `/api/v1` here produces requests to routes
     *                        that do not exist, and because unmatched `/api/*`
     *                        paths fall through to root-mounted routers they
     *                        answer 400/401 rather than 404 — which sends you to
     *                        debug auth for what is really a typo in a URL.
     * @param HttpClient|null $httpClient Inject to reuse your app's HTTP stack,
     *                                    or a stub in tests. Defaults to curl.
     * @param int $timeoutMs Whole-request budget. Default 15s.
     *
     * @throws GurbApiException INVALID_API_KEY if the key is not shaped like one.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?HttpClient $httpClient = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ) {
        // Checked here, not on first request: an integrator who pasted a session
        // JWT — or a super-admin key — should be told what they did, not handed
        // a 401 or a 403 from a route that was never the problem.
        ApiKey::assertValid($apiKey);

        // Null means "use the bundled transport", and the Requester decides what
        // that is — see Requester::defaultHttpClient(). The base URL is not kept
        // on this object either: one copy, held by the thing that builds URLs
        // from it, is one fewer place for a trailing slash to be handled twice.
        $this->requester = new Requester(
            $apiKey,
            \rtrim($baseUrl, '/'),
            $httpClient,
            $timeoutMs,
        );

        $this->community = new CommunityResource($this->requester);
        $this->communityRequests = new CommunityRequestsResource($this->requester);
        $this->tweets = new TweetsResource($this->requester);
        $this->events = new EventsResource($this->requester);
        $this->blogs = new BlogsResource($this->requester);
        $this->albums = new AlbumsResource($this->requester);
        $this->groups = new GroupsResource($this->requester);
        $this->consultants = new ConsultantsResource($this->requester);
        $this->projects = new ProjectsResource($this->requester);
        $this->projects2 = new Projects2Resource($this->requester);
        $this->awards = new AwardsResource($this->requester);
        $this->tasks = new TasksResource($this->requester);
        $this->comments = new CommentsResource($this->requester);
        $this->likes = new LikesResource($this->requester);
        $this->sidebar = new SidebarResource($this->requester);
        $this->advertisements = new AdvertisementsResource($this->requester);
        $this->members = new MembersResource($this->requester);
    }

    // ─── Community creation ──────────────────────────────────────────────────

    /**
     * Ask for a new community to be created. Returns a PENDING request, never a
     * community — see `CommunityResource::requestCreation()`, which this simply
     * forwards to so the operation is reachable under the name the other SDKs
     * use as well.
     *
     * @throws GurbApiException
     */
    public function requestCommunityCreation(CreateCommunityInput $input): CommunityRequest
    {
        return $this->community->requestCreation($input);
    }

    // ─── Embed ───────────────────────────────────────────────────────────────

    /**
     * Mint a short-lived, single-use token for one of your logged-in users.
     *
     * Call this from your backend, per page load, and hand the token to the
     * browser. Do not cache it: it is valid for ~120 seconds and dies on first
     * use, which is exactly what makes it safe to put in a URL fragment.
     *
     * NOTE THE MISSING PARAMETER: there is no `$role`. Every embedded identity
     * lands as a plain MEMBER. Adding a role argument would turn a leaked key
     * into a way to manufacture moderators of the community it belongs to — the
     * blast radius of a stolen key stops at "can create fake members".
     *
     * @param string $externalUserId Your own id for this user. Opaque to Gurb
     *                               and stable forever: it is the join key, so
     *                               reusing one for a different human hands them
     *                               the first person's account inside your
     *                               community. Never pass an email or a username
     *                               a user can change.
     *
     * @throws GurbApiException
     */
    public function createEmbedSession(
        string $externalUserId,
        string $displayName,
        ?string $email = null,
        ?string $avatarUrl = null,
    ): EmbedSession {
        $body = [
            'externalUserId' => $externalUserId,
            'displayName' => $displayName,
        ];

        // Omitted rather than sent as null, so the request body matches the
        // TypeScript SDK's byte for byte and the backend's optional-field
        // validation sees "absent", not "explicitly null".
        if ($email !== null) {
            $body['email'] = $email;
        }
        if ($avatarUrl !== null) {
            $body['avatarUrl'] = $avatarUrl;
        }

        return EmbedSession::fromArray($this->requester->request('POST', 'embed/sessions', body: $body));
    }

    /**
     * Build the iframe URL for a minted token.
     *
     * The token goes in the FRAGMENT (`#t=`), never the query string. Fragments
     * are not sent to the server, so the token never reaches nginx access logs,
     * `Referer` headers on outbound links, or whatever analytics the host page
     * runs. In a query string it would be written to disk in three places before
     * the page finished loading.
     */
    public function buildEmbedUrl(string $slug, EmbedSection $section, string $token): string
    {
        return \sprintf(
            '%s/embed/%s/%s#t=%s',
            $this->requester->baseUrl(),
            \rawurlencode($slug),
            $section->value,
            \rawurlencode($token),
        );
    }

    /**
     * The `<script>` + `GurbEmbed.mount({...})` block for a page.
     *
     * Convenience only — it just forwards this client's `baseUrl` so staging and
     * production do not need configuring twice. Use `new EmbedSnippet()` directly
     * in a view layer that has no business holding an API key.
     */
    public function embedSnippet(): EmbedSnippet
    {
        return new EmbedSnippet($this->requester->baseUrl());
    }
}
