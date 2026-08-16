<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\Model\Advertisement;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Community settings, branding, legal text, the sidebar and advertisements —
 * the wire contract.
 *
 * WHY THESE ASSERTIONS AND NOT "does it work"
 *
 * Every method here is a PUBLISHED CONTRACT. Once a customer's integration calls
 * it, changing a path or a verb breaks their production silently, at a moment we
 * do not choose and cannot roll back for them. Pinning the verb and the URL in a
 * test means such a change fails here instead of there.
 *
 * These assertions are deliberately IDENTICAL to the ones in the TypeScript
 * SDK's customization suite. The two libraries describe ONE contract, and the
 * only thing that stops them drifting apart is that both are pinned to the same
 * claims. If you change one file, change the other in the same commit.
 */
final class CustomizationTest extends TestCase
{
    private const KEY = 'gurb_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function clientReturning(array $data, ?StubHttpClient &$http = null): GurbClient
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => $data]);

        return new GurbClient(self::KEY, 'https://x.test', $http);
    }

    // ─── Settings ────────────────────────────────────────────────────────────

    #[Test]
    public function settings_are_read_and_patched_at_the_community_settings_path(): void
    {
        $client = $this->clientReturning($this->settingsRow(), $http);

        $client->community->getSettings();
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/community/settings', $http->lastCall()->url);

        // PATCH and not PUT. The write is a merge on the other side whatever the
        // verb says, and PATCH is the honest name for it — sending PUT would
        // tell an integrator this replaces the settings blob, which is exactly
        // the belief that wipes a community's home widgets.
        $client->community->updateSettings(['pageVisibility' => ['news' => false]]);
        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/community/settings', $http->lastCall()->url);
    }

    #[Test]
    public function a_settings_patch_carries_only_the_keys_the_caller_passed(): void
    {
        // THE SAFETY PROPERTY OF THIS WHOLE ENDPOINT. The server merges
        // `{...stored, ...patch}` with a deep merge for `pageVisibility` ONLY,
        // so any other key present replaces its stored counterpart wholesale. An
        // SDK that helpfully filled in defaults would silently wipe a
        // community's widget layout and answer 200.
        $client = $this->clientReturning($this->settingsRow(), $http);
        $client->community->updateSettings(['pageVisibility' => ['news' => false]]);

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['pageVisibility'], \array_keys($body));
        self::assertSame(['news' => false], $body['pageVisibility']);
    }

    #[Test]
    public function an_empty_settings_patch_is_refused_before_any_request(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->community->updateSettings([]);
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertSame(0, $e->status());
            self::assertCount(0, $http->calls, 'an empty patch is an upstream bug, not a no-op');
        }
    }

    #[Test]
    public function page_visibility_decodes_as_a_boolean_map(): void
    {
        // A stray "false" STRING coerced to true would report a hidden menu
        // section as visible, so non-booleans are dropped rather than coerced.
        $client = $this->clientReturning($this->settingsRow(), $http);
        $settings = $client->community->getSettings();

        self::assertSame(['home' => true, 'news' => false], $settings->pageVisibility);
        // Absent `directMessagesEnabled` reads as TRUE, matching the server.
        self::assertTrue($settings->directMessagesEnabled);
        // Colours ride along with the READ and are absent from the write's
        // answer, so the model reports them as null rather than inventing them.
        self::assertNotNull($settings->branding);
        self::assertSame('#093431', $settings->branding->primaryColor);
    }

    // ─── Branding ────────────────────────────────────────────────────────────

    #[Test]
    public function branding_is_patched_at_its_own_path(): void
    {
        $client = $this->clientReturning($this->communityRow(), $http);
        $profile = $client->community->updateBranding(['name' => 'قُرب']);

        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/community/branding', $http->lastCall()->url);

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['name'], \array_keys($body));

        self::assertSame('PUBLIC', $profile->type);
        self::assertTrue($profile->isPublic());
    }

    #[Test]
    public function an_empty_branding_patch_is_refused_before_any_request(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->community->updateBranding([]);
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertSame(0, $e->status());
            self::assertCount(0, $http->calls);
        }
    }

    // ─── Legal ───────────────────────────────────────────────────────────────

    #[Test]
    public function legal_text_is_read_and_patched_at_the_community_legal_path(): void
    {
        $client = $this->clientReturning($this->legalRow(), $http);

        $client->community->getLegal();
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/community/legal', $http->lastCall()->url);

        $client->community->updateLegal(terms: 'الشروط');
        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/community/legal', $http->lastCall()->url);
    }

    #[Test]
    public function an_untouched_legal_field_is_absent_and_a_blank_one_is_sent(): void
    {
        // THE DISTINCTION THIS ENDPOINT TURNS ON. The server decides by asking
        // whether the KEY IS PRESENT: absent means "keep the stored text", and
        // present-but-empty means "restore the platform's standard template". A
        // null would satisfy the presence check and read as a deliberate wipe.
        $client = $this->clientReturning($this->legalRow(), $http);

        $client->community->updateLegal(terms: 'الشروط');
        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['terms'], \array_keys($body), 'privacyPolicy was not touched and must not be sent');

        $client->community->updateLegal(privacyPolicy: '');
        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['privacyPolicy'], \array_keys($body));
        self::assertSame('', $body['privacyPolicy'], 'an empty string is an instruction, not an omission');
    }

    #[Test]
    public function a_legal_patch_with_neither_text_is_refused_before_any_request(): void
    {
        // `language` alone only changes which template a BLANK field resolves
        // to, which is invisible unless a field is also being cleared.
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->community->updateLegal(language: 'en');
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('privacyPolicy', $e->getMessage());
            self::assertCount(0, $http->calls);
        }
    }

    // ─── Sidebar ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_sidebar_is_read_written_and_reset_at_one_path(): void
    {
        $client = $this->clientReturning($this->sidebarRow(), $http);

        $client->sidebar->get();
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/customization/sidebar', $http->lastCall()->url);

        // ⚠️ PUT, NOT PATCH, AND ON PURPOSE. This is the one write on this
        // surface the platform genuinely serves as a replace: sending `items`
        // replaces the whole array. Normalising it to PATCH would suggest a
        // per-item merge that does not exist, and a caller who believed that
        // would lose every menu entry their read did not contain.
        $client->sidebar->update(items: [['id' => 'home']]);
        self::assertSame('PUT', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/customization/sidebar', $http->lastCall()->url);

        $client->sidebar->reset();
        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/customization/sidebar', $http->lastCall()->url);
    }

    #[Test]
    public function the_default_menu_and_the_icon_vocabulary_are_their_own_reads(): void
    {
        $client = $this->clientReturning($this->sidebarRow(), $http);
        $client->sidebar->default();
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/customization/sidebar/default', $http->lastCall()->url);

        $client = $this->clientReturning(['icons' => ['home', 'users', 7]], $http);
        $icons = $client->sidebar->icons();
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/customization/sidebar/icons', $http->lastCall()->url);
        // Non-strings dropped rather than coerced — an icon named "7" is not an
        // icon this menu can render.
        self::assertSame(['home', 'users'], $icons);
    }

    #[Test]
    public function an_untouched_sidebar_field_is_absent_from_the_body(): void
    {
        // Omitting `items` is how you toggle the menu off without replacing it.
        // A null would be a value the server has to interpret.
        $client = $this->clientReturning($this->sidebarRow(), $http);
        $client->sidebar->update(enabled: false);

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['enabled'], \array_keys($body));
    }

    #[Test]
    public function a_sidebar_update_with_nothing_to_change_is_refused_before_any_request(): void
    {
        $http = StubHttpClient::json(200, ['success' => true, 'data' => []]);
        $client = new GurbClient(self::KEY, 'https://x.test', $http);

        try {
            $client->sidebar->update();
            self::fail('expected a local refusal');
        } catch (GurbApiException $e) {
            self::assertSame(0, $e->status());
            self::assertCount(0, $http->calls);
        }
    }

    #[Test]
    public function a_sparse_items_array_is_re_indexed_into_a_json_list(): void
    {
        // A PHP array with gaps in its keys — the usual result of an
        // array_filter() upstream — encodes as a JSON OBJECT, and the server
        // rejects `{"1": {...}}` where it expected a list.
        $client = $this->clientReturning($this->sidebarRow(), $http);
        $client->sidebar->update(items: [1 => ['id' => 'home'], 3 => ['id' => 'news']]);

        $raw = (string) $http->lastCall()->body;
        self::assertStringContainsString('"items":[{', $raw);
    }

    // ─── Advertisements ──────────────────────────────────────────────────────

    #[Test]
    public function the_member_facing_feed_is_its_own_path(): void
    {
        $client = $this->clientReturning(['items' => [$this->adRow()]], $http);
        $ads = $client->advertisements->active();

        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame(
            'https://x.test/api/sdk/customization/advertisements/active',
            $http->lastCall()->url,
        );
        self::assertInstanceOf(Advertisement::class, $ads[0]);
        self::assertSame('2026-02-01T00:00:00.000Z', $ads[0]->startsAt);
    }

    #[Test]
    public function an_omitted_position_filter_never_reaches_the_query_string(): void
    {
        $client = $this->clientReturning(['items' => []], $http);

        $client->advertisements->active();
        self::assertSame(
            'https://x.test/api/sdk/customization/advertisements/active',
            $http->lastCall()->url,
        );

        $client->advertisements->active('RIGHT_SIDEBAR');
        self::assertSame(
            'https://x.test/api/sdk/customization/advertisements/active?position=RIGHT_SIDEBAR',
            $http->lastCall()->url,
        );
    }

    #[Test]
    public function the_admin_list_update_and_delete_share_the_advertisements_path(): void
    {
        $client = $this->clientReturning(['items' => [$this->adRow()], 'total' => 1], $http);

        $client->advertisements->list();
        self::assertSame('GET', $http->lastCall()->method);
        self::assertSame('https://x.test/api/sdk/customization/advertisements', $http->lastCall()->url);

        // PATCH and not PUT: the update is partial in fact, and PUT would tell
        // an integrator that omitting a field clears it.
        $client->advertisements->update('ad_1', ['isActive' => false]);
        self::assertSame('PATCH', $http->lastCall()->method);
        self::assertSame(
            'https://x.test/api/sdk/customization/advertisements/ad_1',
            $http->lastCall()->url,
        );

        $client->advertisements->delete('ad_1');
        self::assertSame('DELETE', $http->lastCall()->method);
        self::assertSame(
            'https://x.test/api/sdk/customization/advertisements/ad_1',
            $http->lastCall()->url,
        );
    }

    #[Test]
    public function an_ad_id_containing_a_slash_cannot_walk_onto_another_route(): void
    {
        // Unencoded, "a/b" turns this into a path this SDK never meant to call —
        // which on this backend answers 400 "Tenant context required" rather
        // than 404, sending the integrator to audit their credentials.
        $client = $this->clientReturning([], $http);

        $client->advertisements->delete('a/b');
        self::assertSame(
            'https://x.test/api/sdk/customization/advertisements/a%2Fb',
            $http->lastCall()->url,
        );

        $client->advertisements->update('a/b', ['isActive' => true]);
        self::assertSame(
            'https://x.test/api/sdk/customization/advertisements/a%2Fb',
            $http->lastCall()->url,
        );
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    private function settingsRow(): array
    {
        return [
            'branding' => [
                'primaryColor' => '#093431',
                'secondaryColor' => null,
                'isExplorable' => true,
            ],
            'pageVisibility' => ['home' => true, 'news' => false, 'broken' => 'false'],
            'homeWidgets' => null,
            'advertisements' => ['enabled' => false],
            'backButton' => null,
            'widgetConfig' => null,
            'guestAccess' => ['sections' => []],
            'sidebarCustomization' => null,
        ];
    }

    private function communityRow(): array
    {
        return [
            'id' => 'com_1',
            'slug' => 'qurb',
            'name' => 'قُرب',
            'type' => 'PUBLIC',
            'updatedAt' => '2026-01-01T00:00:00.000Z',
        ];
    }

    private function legalRow(): array
    {
        return [
            'terms' => 'الشروط',
            'privacyPolicy' => 'الخصوصية',
            'isDefaultTerms' => false,
            'isDefaultPrivacyPolicy' => true,
            'termsVersion' => '1',
            'privacyVersion' => '1',
            'language' => 'ar',
            'updatedAt' => '2026-01-01T00:00:00.000Z',
        ];
    }

    private function sidebarRow(): array
    {
        return [
            'enabled' => true,
            'items' => [['id' => 'home']],
            'lastModified' => '2026-01-01T00:00:00.000Z',
            'modifiedByUserId' => 'usr_1',
        ];
    }

    private function adRow(): array
    {
        return [
            'id' => 'ad_1',
            'title' => 'إعلان',
            'imageUrl' => 'https://cdn.test/a.png',
            'position' => 'RIGHT_SIDEBAR',
            'isActive' => true,
            'isGuestVisible' => false,
            'clickCount' => 3,
            'impressionCount' => 90,
            'startsAt' => '2026-02-01T00:00:00.000Z',
            'endsAt' => null,
            'createdAt' => '2026-01-01T00:00:00.000Z',
            'updatedAt' => null,
        ];
    }
}
