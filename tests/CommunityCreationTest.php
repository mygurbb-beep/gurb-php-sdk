<?php

declare(strict_types=1);

namespace Gurb\Tests;

use Gurb\GurbAdminClient;
use Gurb\GurbApiException;
use Gurb\GurbClient;
use Gurb\GurbErrorCode;
use Gurb\Input\CreateCommunityInput;
use Gurb\Input\ExternalOwner;
use Gurb\Resource\Admin\AdminCommunitiesResource;
use Gurb\Tests\Support\StubHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `$admin->communities->create(name:, slug:)` — two fields and nothing else.
 *
 * The point of every rejection test below is not merely that it throws: it is
 * that it throws WITHOUT SENDING ANYTHING. A guard that fails after the request
 * has gone is not a guard, it is a second opinion — the community may already
 * exist by the time you read the exception. So each case asserts the transport
 * recorded zero calls.
 */
final class CommunityCreationTest extends TestCase
{
    private const ADMIN_KEY = 'gurb_sa_a1b2c3d4e5f6_' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private function admin(StubHttpClient $http): GurbAdminClient
    {
        return new GurbAdminClient(self::ADMIN_KEY, 'https://x.test', $http);
    }

    /**
     * Assert a local rejection: right code, right status, message names the
     * rule, and not one byte left the process.
     */
    private function assertRejectedLocally(string $name, string $slug, string $expectedInMessage): void
    {
        $http = StubHttpClient::json(201, ['data' => []]);

        try {
            $this->admin($http)->communities->create(name: $name, slug: $slug);
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertSame(GurbErrorCode::VALIDATION_ERROR, $e->code());
            self::assertSame(0, $e->status(), 'status 0 — no server ever saw this');
            self::assertFalse($e->isRetryable(), 'retrying a bad argument never helps');
            self::assertStringContainsString(
                $expectedInMessage,
                $e->getMessage(),
                'the message must name the rule that was broken',
            );
        }

        self::assertCount(0, $http->calls, 'the guard must fire BEFORE any HTTP call');
    }

    // ─── The happy path ──────────────────────────────────────────────────────

    #[Test]
    public function it_posts_exactly_name_and_slug_and_nothing_else(): void
    {
        $http = StubHttpClient::json(201, [
            'data' => ['id' => 'cmt_1', 'slug' => 'book-club', 'name' => 'نادي القراءة', 'type' => 'PRIVATE'],
        ]);

        $community = $this->admin($http)->communities->create(name: 'نادي القراءة', slug: 'book-club');

        self::assertSame('POST', $http->lastCall()->method);
        self::assertSame('https://x.test/api/admin/sdk/communities', $http->lastCall()->url);
        self::assertSame(
            '{"name":"نادي القراءة","slug":"book-club"}',
            (string) $http->lastCall()->body,
            'the exact bytes: two keys, in this order, and no others',
        );
        self::assertSame('cmt_1', $community->id);
        self::assertFalse($community->isPublic(), 'the server decides visibility, and it decides PRIVATE');
    }

    #[Test]
    public function a_name_is_trimmed_before_it_is_sent(): void
    {
        $http = StubHttpClient::json(201, ['data' => []]);

        $this->admin($http)->communities->create(name: '  نادي القراءة  ', slug: 'book-club');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('نادي القراءة', $body['name'], 'leading and trailing whitespace is a paste artefact');
    }

    #[Test]
    public function the_slug_is_sent_verbatim_and_is_not_derived_from_the_name(): void
    {
        // Deriving a slug from an Arabic name would mean transliterating, and a
        // transliteration is a guess that ends up in a permanent URL.
        $http = StubHttpClient::json(201, ['data' => []]);

        $this->admin($http)->communities->create(name: 'نادي القراءة', slug: 'reading-club-2026');

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('reading-club-2026', $body['slug']);
    }

    // ─── Slug rules, one failing case each ───────────────────────────────────

    #[Test]
    public function an_empty_slug_names_what_a_slug_is(): void
    {
        $this->assertRejectedLocally('نادي', '', 'A community slug is required');
    }

    #[Test]
    public function an_uppercase_slug_is_told_the_lowercase_form_it_should_have_used(): void
    {
        // The most common mistake by a distance, and the most annoying to
        // diagnose from a generic message. The fix is handed over.
        $this->assertRejectedLocally('نادي', 'Book-Club', 'Community slugs are lowercase');

        try {
            $this->admin(StubHttpClient::json(201, ['data' => []]))
                ->communities->create(name: 'نادي', slug: 'Book-Club');
            self::fail('Expected a GurbApiException.');
        } catch (GurbApiException $e) {
            self::assertStringContainsString('"book-club" would be', $e->getMessage());
        }
    }

    #[Test]
    #[DataProvider('slugsWithForbiddenCharacters')]
    public function a_slug_with_a_forbidden_character_is_refused(string $slug): void
    {
        $this->assertRejectedLocally('نادي', $slug, 'lowercase letters (a-z)');
    }

    /** @return iterable<string, array{string}> */
    public static function slugsWithForbiddenCharacters(): iterable
    {
        yield 'a space' => ['book club'];
        yield 'an underscore' => ['book_club'];
        yield 'a dot' => ['book.club'];
        yield 'a slash — would break out of the path' => ['book/club'];
        yield 'arabic — that is what $name is for' => ['نادي'];
        yield 'an accent' => ['café'];
        yield 'a percent-encoding someone pre-escaped' => ['book%20club'];
    }

    #[Test]
    public function a_slug_may_not_start_with_a_hyphen(): void
    {
        $this->assertRejectedLocally('نادي', '-book-club', 'may not start or end with a hyphen');
    }

    #[Test]
    public function a_slug_may_not_end_with_a_hyphen(): void
    {
        $this->assertRejectedLocally('نادي', 'book-club-', 'may not start or end with a hyphen');
    }

    #[Test]
    public function a_slug_may_not_contain_consecutive_hyphens(): void
    {
        $this->assertRejectedLocally('نادي', 'book--club', 'may not contain consecutive hyphens');
    }

    #[Test]
    public function a_slug_shorter_than_the_minimum_is_refused(): void
    {
        $this->assertRejectedLocally('نادي', 'ab', 'must be 3 to 50 characters');
    }

    #[Test]
    public function a_slug_longer_than_the_maximum_is_refused(): void
    {
        $tooLong = \str_repeat('a', AdminCommunitiesResource::SLUG_MAX_LENGTH + 1);
        $this->assertRejectedLocally('نادي', $tooLong, 'must be 3 to 50 characters');
    }

    #[Test]
    #[DataProvider('slugsAtTheBoundary')]
    public function a_slug_exactly_at_a_boundary_is_accepted(string $slug): void
    {
        // Off-by-one in a validator is the classic way to reject a legitimate
        // customer, so both ends are pinned.
        $http = StubHttpClient::json(201, ['data' => []]);

        $this->admin($http)->communities->create(name: 'نادي', slug: $slug);

        self::assertCount(1, $http->calls, "\"{$slug}\" should have been accepted");
    }

    /** @return iterable<string, array{string}> */
    public static function slugsAtTheBoundary(): iterable
    {
        yield 'exactly the minimum' => [\str_repeat('a', AdminCommunitiesResource::SLUG_MIN_LENGTH)];
        yield 'exactly the maximum' => [\str_repeat('a', AdminCommunitiesResource::SLUG_MAX_LENGTH)];
        yield 'digits only' => ['2026'];
        yield 'single internal hyphen' => ['a-b'];
        yield 'several internal hyphens' => ['my-book-club-2026'];
    }

    // ─── Name rules ──────────────────────────────────────────────────────────

    #[Test]
    public function an_empty_name_is_refused(): void
    {
        $this->assertRejectedLocally('', 'book-club', 'A community name is required');
    }

    #[Test]
    public function a_whitespace_only_name_is_refused_and_says_so(): void
    {
        // Distinguished from "" on purpose: the caller passed something, and
        // "is required" would read as though they had not.
        $this->assertRejectedLocally("  \n\t ", 'book-club', 'cannot be only whitespace');
    }

    #[Test]
    public function a_name_longer_than_the_maximum_is_refused(): void
    {
        $tooLong = \str_repeat('ن', AdminCommunitiesResource::NAME_MAX_LENGTH + 1);
        $this->assertRejectedLocally($tooLong, 'book-club', 'at most 100 characters');
    }

    #[Test]
    public function an_arabic_name_is_measured_in_characters_and_not_bytes(): void
    {
        // THE BUG THIS PREVENTS: strlen('نادي') is 8, not 4. Measuring bytes
        // would reject an ordinary 60-character Arabic name for being "over
        // 100" — a limit that only misfires in the language the product is
        // actually used in, which is the worst place for it to misfire.
        $name = \str_repeat('ن', AdminCommunitiesResource::NAME_MAX_LENGTH);
        self::assertSame(200, \strlen($name), 'twice the bytes of its character count');

        $http = StubHttpClient::json(201, ['data' => []]);
        $this->admin($http)->communities->create(name: $name, slug: 'book-club');

        self::assertCount(1, $http->calls, 'exactly 100 characters is within the limit');
    }

    #[Test]
    public function the_name_length_limit_counts_the_trimmed_value(): void
    {
        $name = ' ' . \str_repeat('ن', AdminCommunitiesResource::NAME_MAX_LENGTH) . ' ';

        $http = StubHttpClient::json(201, ['data' => []]);
        $this->admin($http)->communities->create(name: $name, slug: 'book-club');

        self::assertCount(1, $http->calls, 'padding is not content and must not consume the budget');
    }

    #[Test]
    public function a_malformed_utf8_name_is_refused_rather_than_stored(): void
    {
        // A lone continuation byte. Sending it would have it stored, rendered
        // and re-served, and the corruption blamed on whichever screen showed
        // it last.
        $this->assertRejectedLocally("\x80\x80\x80", 'book-club', 'not valid UTF-8');
    }

    // ─── The removed parameters ──────────────────────────────────────────────

    #[Test]
    public function create_accepts_two_required_parameters_and_one_honoured_option(): void
    {
        // THE RULE THIS DEFENDS IS NOT "two parameters". It is that every
        // parameter reaches the server and changes something. A parameter that
        // is silently discarded is worse than one that does not exist, because
        // the caller believes it worked.
        //
        // `owner` was added in 0.5.0 and passes that test: the server resolves
        // it to a real account and founds the community with it. `description`,
        // `type` and `ownerUserId` do NOT, which is why they are still absent —
        // see the sibling test below.
        $method = new \ReflectionMethod(AdminCommunitiesResource::class, 'create');
        $names = \array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters(),
        );

        self::assertSame(
            ['name', 'slug', 'owner', 'ownerEmail', 'ownerFirstName', 'ownerFamilyName'],
            $names,
        );

        $byName = [];
        foreach ($method->getParameters() as $parameter) {
            $byName[$parameter->getName()] = $parameter;
        }

        self::assertFalse($byName['name']->isOptional(), 'a community must be named');
        self::assertFalse($byName['slug']->isOptional(), 'a community must have a URL');

        // Optional, because the overwhelmingly common case is that the caller's
        // own credential founds the community. Making it required would force
        // every existing integration to invent an identity provider.
        self::assertTrue(
            $byName['owner']->isOptional(),
            'owner is opt-in: without it the calling credential founds the community',
        );
        // The simple path. An integrator knows their own e-mail; nobody knows a
        // uuid without looking it up, and a provider assertion is a whole
        // registration step. Every hop between "I want a community" and "I own
        // it" is a hop where it ends up owned by the platform admin instead.
        self::assertTrue($byName['ownerEmail']->isOptional());
        self::assertNull($byName['ownerEmail']->getDefaultValue());
        self::assertNull(
            $byName['owner']->getDefaultValue(),
            'the default must be null, not a fabricated owner',
        );
    }

    #[Test]
    public function an_omitted_owner_puts_no_owner_key_on_the_wire(): void
    {
        // Sending "owner": null would be read by the server as a malformed
        // assertion rather than as an absent one. Absence must mean absence.
        $http = StubHttpClient::json(201, ['data' => []]);
        $this->admin($http)->communities->create(name: 'نادي', slug: 'book-club');

        $body = \json_decode((string) $http->calls[0]->body, true);
        self::assertSame(['name', 'slug'], \array_keys($body));
    }

    #[Test]
    public function an_owner_is_sent_with_its_verification_claim_intact(): void
    {
        $http = StubHttpClient::json(201, ['data' => []]);
        $this->admin($http)->communities->create(
            name: 'نادي',
            slug: 'book-club',
            owner: new ExternalOwner(
                provider: 'okta',
                externalUserId: '00u123',
                email: 'A.Ali@Corp.com',
                // False, and it must survive as false. A client that "helpfully"
                // upgraded this to true would be manufacturing the single claim
                // the server's auto-link branch rests on.
                emailVerified: false,
            ),
        );

        $body = \json_decode((string) $http->calls[0]->body, true);
        self::assertSame('okta', $body['owner']['provider']);
        self::assertSame('00u123', $body['owner']['externalUserId']);
        self::assertFalse($body['owner']['emailVerified']);
        self::assertArrayHasKey(
            'emailVerified',
            $body['owner'],
            'the claim is always stated explicitly, never left to a server default',
        );
    }

    #[Test]
    public function a_blank_owner_field_is_refused_before_any_request(): void
    {
        foreach ([
            ['', '00u123', 'a@b.com', 'provider'],
            ['okta', '  ', 'a@b.com', 'externalUserId'],
            ['okta', '00u123', 'not-an-email', 'email'],
        ] as [$provider, $externalUserId, $email, $expected]) {
            $http = StubHttpClient::json(201, ['data' => []]);

            try {
                $this->admin($http)->communities->create(
                    name: 'نادي',
                    slug: 'book-club',
                    owner: new ExternalOwner($provider, $externalUserId, $email, true),
                );
                self::fail("expected a local refusal naming {$expected}");
            } catch (\Gurb\GurbApiException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
                self::assertCount(0, $http->calls, 'nothing may be sent when the owner is unusable');
            }
        }
    }

    #[Test]
    public function the_request_creation_path_narrowed_to_the_same_two_fields(): void
    {
        // Both community-creation paths carry a name and a slug and nothing
        // else, so `CreateCommunityInput` lost the same three fields that
        // `create()` did. Asserting the whole body rather than the absence of
        // one key: this is the endpoint where a stray field would be easiest to
        // reintroduce, because it used to accept them.
        $http = StubHttpClient::json(201, ['data' => []]);
        $client = new GurbClient(ApiKeyTest::KEY, 'https://x.test', $http);

        $client->requestCommunityCreation(new CreateCommunityInput(name: 'نادي', slug: 'book-club'));

        $body = \json_decode((string) $http->lastCall()->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('https://x.test/api/sdk/community-requests', $http->lastCall()->url);
        self::assertSame(['name' => 'نادي', 'slug' => 'book-club'], $body);
    }

    #[Test]
    public function the_input_object_accepts_exactly_two_constructor_parameters(): void
    {
        $names = \array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionClass(CreateCommunityInput::class))->getConstructor()?->getParameters() ?? [],
        );

        self::assertSame(['name', 'slug'], $names);
    }

    #[Test]
    public function a_community_request_still_reads_a_description_that_came_back(): void
    {
        // A response field is not a request field. Nothing sends a description
        // any more, but older requests carry one and the server still returns
        // it — dropping it from the model would discard data that exists.
        $http = StubHttpClient::json(200, ['data' => [
            'id' => 'creq_1',
            'name' => 'نادي',
            'slug' => 'book-club',
            'description' => 'وصف قديم',
            'status' => 'PENDING',
            'requestedByUserId' => 'usr_1',
            'createdAt' => '2026-08-13T10:00:00.000Z',
        ]]);
        $client = new GurbClient(ApiKeyTest::KEY, 'https://x.test', $http);

        self::assertSame('وصف قديم', $client->communityRequests->get('creq_1')->description);
    }

    // ─── Founding by e-mail ──────────────────────────────────────────────────

    #[Test]
    public function an_owner_email_is_sent_lowercased_and_alone(): void
    {
        $http = StubHttpClient::json(201, ['data' => []]);
        $this->admin($http)->communities->create(
            name: 'نادي',
            slug: 'book-club',
            ownerEmail: '  Ahmed@Corp.com  ',
        );

        $body = \json_decode((string) $http->calls[0]->body, true);
        self::assertSame(['name', 'slug', 'ownerEmail'], \array_keys($body));
        // Trimmed here; the SERVER lowercases, because that is where the
        // comparison against stored addresses happens. Sending it untrimmed
        // would make "  a@b.com" a different account from "a@b.com".
        self::assertSame('Ahmed@Corp.com', $body['ownerEmail']);
    }

    #[Test]
    public function optional_owner_names_are_omitted_when_blank(): void
    {
        // Absence must mean absence. An empty string is a value the server has
        // to interpret, and its answer would be a 400 for a field the caller
        // simply left alone.
        $http = StubHttpClient::json(201, ['data' => []]);
        $this->admin($http)->communities->create(
            name: 'نادي',
            slug: 'book-club',
            ownerEmail: 'a@b.com',
            ownerFirstName: '   ',
        );

        $body = \json_decode((string) $http->calls[0]->body, true);
        self::assertArrayNotHasKey('ownerFirstName', $body);
    }

    #[Test]
    public function owner_and_ownerEmail_together_are_refused_before_any_request(): void
    {
        // They answer the same question two ways, and there is no useful
        // behaviour for the contradiction. Refusing locally costs no round trip
        // and names the distinction, which a server 400 could not.
        $http = StubHttpClient::json(201, ['data' => []]);

        try {
            $this->admin($http)->communities->create(
                name: 'نادي',
                slug: 'book-club',
                owner: new ExternalOwner('okta', '00u1', 'a@b.com', true),
                ownerEmail: 'a@b.com',
            );
            self::fail('expected a local refusal');
        } catch (\Gurb\GurbApiException $e) {
            self::assertStringContainsString('not both', $e->getMessage());
            self::assertCount(0, $http->calls);
        }
    }
}
