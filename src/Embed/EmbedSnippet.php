<?php

declare(strict_types=1);

namespace Gurb\Embed;

use Gurb\ApiKey;
use Gurb\EmbedSection;
use InvalidArgumentException;

/**
 * Renders the host page's `<script>` snippet and the `GurbEmbed.mount({...})`
 * call for a token your PHP app just minted.
 *
 * There is no PHP equivalent of `@gurb/embed`, and there should not be: that
 * package runs in the visitor's browser, and PHP finished running before the
 * browser saw anything. What PHP can usefully own is the handoff — printing the
 * mount call with a fresh token already in it, so a Blade/Twig template does not
 * hand-assemble a script tag around a credential.
 *
 * The tradeoff of printing the token into HTML: it lands in the page source, and
 * therefore in any full-page cache. That is acceptable only because embed tokens
 * are single-use and expire in ~120 seconds. If your framework caches rendered
 * HTML for longer than that, use renderAsync() instead, which prints a fetch
 * callback and keeps the token out of the markup entirely.
 */
final class EmbedSnippet
{
    private const DEFAULT_BASE_URL = 'https://mygurb.com';

    /** The UMD build of @gurb/embed, which exposes the global `GurbEmbed`. */
    private const DEFAULT_SCRIPT_URL = 'https://unpkg.com/@gurb/embed/dist/index.global.js';

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly string $scriptUrl = self::DEFAULT_SCRIPT_URL,
    ) {
    }

    /**
     * Print everything the page needs: a container, the loader, the mount call.
     *
     * Loads `@gurb/embed` from `$scriptUrl` (unpkg by default) and calls
     * `GurbEmbed.mount()`. Use this when you want the loader's behaviour —
     * auto-resize once the frame reports its height, and a single mount point
     * you can restyle.
     *
     * If you would rather not depend on an external script at all, `iframe()`
     * emits a plain frame and loads nothing. Both reach the same Gurb page; they
     * differ only in who builds the element.
     *
     * @param string $targetId      DOM id for the container div.
     * @param string $token         A token from GurbClient::createEmbedSession().
     * @param int|null $initialHeight Height before the frame reports its own.
     *                               Defaults per section shape — a whole
     *                               community needs far more room than one pane.
     * @return string HTML, safe to echo directly.
     */
    public function render(
        string $targetId,
        string $slug,
        EmbedSection $section,
        string $token,
        ?int $initialHeight = null,
        bool $autoResize = true,
    ): string {
        $this->assertNotASecret($token);

        return $this->wrap($targetId, [
            'target' => '#' . $targetId,
            'slug' => $slug,
            'section' => $section->value,
            // Null rather than a literal 600, so the default follows the SHAPE
            // being embedded. A whole community at 600px shows a navigation bar
            // and little else in the moment before auto-resize lands, and that
            // moment is when someone decides the integration is broken. Passing
            // a number still wins — this only changes what "unspecified" means.
            'initialHeight' => $initialHeight ?? $section->defaultHeight(),
            'baseUrl' => $this->baseUrl,
            'autoResize' => $autoResize,
            'token' => $token,
        ]);
    }

    /**
     * Same, but the browser fetches the token from an endpoint of YOUR app.
     *
     * Prefer this whenever the page might be cached, prerendered, or sat on by a
     * user for a while: a token baked into HTML is often already dead by the
     * time someone scrolls to the frame, and a dead token renders an empty pane
     * with no obvious cause.
     *
     * This one genuinely needs the loader script — fetching a token after page
     * load is something only JavaScript can do, so `iframe()` is not an
     * alternative here.
     *
     * @param string $tokenEndpoint A path on your own site that returns
     *                              `{"token": "..."}`. It must apply YOUR auth —
     *                              whoever can call it can join your community.
     */
    public function renderAsync(
        string $targetId,
        string $slug,
        EmbedSection $section,
        string $tokenEndpoint,
        int $initialHeight = 600,
        bool $autoResize = true,
    ): string {
        return $this->wrap($targetId, [
            'target' => '#' . $targetId,
            'slug' => $slug,
            'section' => $section->value,
            'baseUrl' => $this->baseUrl,
            'initialHeight' => $initialHeight,
            'autoResize' => $autoResize,
        ], tokenEndpoint: $tokenEndpoint);
    }

    /**
     * A plain `<iframe>` and nothing else. NO external script, NO CDN, NO npm.
     *
     * Reach for this when you do not want a third-party script on your page's
     * critical path, or when your CSP forbids one. Everything the embed actually
     * needs is here: the URL, the token in the fragment, and the frame.
     *
     * What you give up versus `render()` is the loader's auto-resize, so pick a
     * `$height` that suits your layout — the frame will not grow by itself.
     *
     * WHY THE FRAGMENT AND NOT A QUERY STRING
     *
     * A fragment is never transmitted to a server. Put the token in a query
     * string instead and it lands in Gurb's nginx access log, in your own if you
     * proxy, and in the `Referer` header of every outbound link the framed page
     * renders. It is a live credential, so that is the difference between a
     * short-lived secret and a logged one.
     *
     * @param string $token A token from GurbClient::createEmbedSession(). It is
     *                      single-use and lives about 120 seconds, so mint it
     *                      when you render the page — never cache this HTML.
     * @param int|null $height Pixels. Defaults per shape: a whole community
     *                         needs far more room than one pane.
     * @param string $style Extra CSS for the frame. Appended after the defaults,
     *                      so it wins.
     *
     * @return string HTML, safe to echo directly.
     */
    public function iframe(
        EmbedSection $section,
        string $token,
        ?int $height = null,
        string $style = '',
    ): string {
        $this->assertNotASecret($token);

        $fragment = ['token' => $token];
        // Omitted for the whole community: absence means "the community home",
        // which is where the embed page sends an arriving member anyway. This
        // matches the loader's contract exactly — see the boundary tests in
        // @gurb/embed, which pin this shape on the other side.
        if (!$section->isWholeCommunity()) {
            $fragment['section'] = $section->value;
        }

        $src = \rtrim($this->baseUrl, '/') . '/embed#' . \http_build_query($fragment);

        $attrs = [
            'src' => $src,
            'title' => 'Gurb — ' . $section->value,
            'loading' => 'lazy',
            'style' => \sprintf(
                'width:100%%;height:%dpx;border:0;%s',
                $height ?? $section->defaultHeight(),
                $style,
            ),
            // Least privilege, and each of these is load-bearing:
            //   allow-same-origin — WITHOUT IT THE EMBED CANNOT WORK AT ALL.
            //     The page stores its session in sessionStorage and rewrites its
            //     own URL; an opaque origin makes both throw.
            //   allow-scripts     — it is a React app.
            //   allow-forms       — posting, commenting, joining.
            //   allow-popups + …popups-to-escape-sandbox — external links in
            //     member content open as ordinary pages rather than inheriting
            //     this sandbox.
            // Deliberately ABSENT: allow-top-navigation. Nothing inside the
            // frame should be able to navigate the host's page away.
            'sandbox' => 'allow-same-origin allow-scripts allow-forms allow-popups '
                . 'allow-popups-to-escape-sandbox',
        ];

        $rendered = '';
        foreach ($attrs as $name => $value) {
            $rendered .= \sprintf(
                ' %s="%s"',
                $name,
                \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return '<iframe' . $rendered . '></iframe>';
    }

    /** @param array<string, mixed> $options */
    private function wrap(string $targetId, array $options, ?string $tokenEndpoint = null): string
    {
        $id = \htmlspecialchars($targetId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $script = \htmlspecialchars($this->scriptUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $json = $this->jsonForScriptTag($options);

        $mountArgs = $tokenEndpoint === null
            ? $json
            : \sprintf(
                "Object.assign(%s, { fetchToken: () => fetch(%s, { method: 'POST', credentials: 'same-origin' }).then(r => r.json()).then(d => d.token) })",
                $json,
                $this->jsonForScriptTag($tokenEndpoint),
            );

        // The loader is a plain blocking script and the mount call follows it
        // directly. No `defer`, no DOMContentLoaded wrapper: both introduce an
        // ordering question ("has the loader run yet?", "has DOMContentLoaded
        // already fired for this AJAX-inserted fragment?") that this arrangement
        // simply does not have — the container is parsed, then the loader runs,
        // then mount runs. Put the snippet where you want the frame.
        return <<<HTML
            <div id="{$id}"></div>
            <script src="{$script}"></script>
            <script>
              GurbEmbed.mount({$mountArgs})
            </script>
            HTML;
    }

    /**
     * JSON-encode for placement inside a `<script>` block.
     *
     * HEX_TAG/HEX_AMP/HEX_APOS/HEX_QUOT are the whole point: without them a slug
     * or token containing `</script>` closes the block early and everything after
     * it becomes markup the browser will happily execute. htmlspecialchars is the
     * wrong tool here — inside a script element the HTML parser does not decode
     * entities, so escaping that way would corrupt the value instead.
     */
    private function jsonForScriptTag(mixed $value): string
    {
        return \json_encode(
            $value,
            \JSON_THROW_ON_ERROR
            | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT
            | \JSON_UNESCAPED_UNICODE
            | \JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Refuse to print a secret API key into a web page.
     *
     * The TypeScript SDK keeps the key out of the browser with a separate
     * package plus a `window` check. PHP has neither problem and neither
     * defence: one process holds the key AND writes the HTML, so the one way to
     * leak it is passing it where a token belongs — both are "the Gurb
     * credential" to someone wiring this up quickly. This is that guard.
     */
    private function assertNotASecret(string $token): void
    {
        if (ApiKey::looksLikeSecret($token)) {
            throw new InvalidArgumentException(
                'That is a secret API key, not an embed token. Never render an API key into a page — mint an embed token with GurbClient::createEmbedSession().',
            );
        }
        if ($token === '') {
            throw new InvalidArgumentException('Empty embed token.');
        }
    }
}
