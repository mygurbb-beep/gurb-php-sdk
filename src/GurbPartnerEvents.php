<?php

declare(strict_types=1);

namespace Gurb;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Gurb\Http\CurlHttpClient;
use Gurb\Http\HttpClient;
use Gurb\Http\HttpRequest;
use Gurb\Http\TransportException;

/**
 * Partner events — Gurb Partner Sign-In, events contract v1. The PHP twin of
 * `GurbPartnerEvents` in @gurb/server 1.8.0: same envelope, same limits, same
 * outcomes.
 *
 * A HOST (a partner site whose members sign in to a Gurb community through it)
 * tells Gurb whether each member may still be inside, and keeps their name,
 * email and photo current, by POSTing signed events to
 *
 *   POST {baseUrl}/api/webhooks/inbound/{communityId}/host
 *
 * signed with Standard Webhooks using the community's EVENTS secret (`whsec_…`)
 * — handed over by a Gurb super admin; it is NOT an API key.
 *
 *   $events = new GurbPartnerEvents($communityId, getenv('GURB_EVENTS_SECRET'));
 *   $event  = $events->accessChanged($sub, $stateVersion, 'blocked');
 *   $outbox->save($event->toArray());              // same transaction as your change
 *   $result = $events->send(PartnerEvent::fromArray($row));   // from the worker
 *
 * A builder that refuses its input throws GurbApiException::localValidation —
 * VALIDATION_ERROR with status 0, exactly like every other local guard here —
 * so there is still one thing to catch.
 *
 * Build and send are separate on purpose, and `send()` never retries by itself:
 * the contract puts retries in the host's outbox (back-off for up to 72 h), and
 * an SDK retrying underneath would multiply every one of them. Each send
 * re-signs with a fresh timestamp (Gurb refuses one more than 5 minutes off)
 * while the id and body stay byte-identical, so a retry is applied once.
 */
final class GurbPartnerEvents
{
    public const DEFAULT_BASE_URL = 'https://mygurb.com';
    public const DEFAULT_TIMEOUT_MS = 10_000;

    /** Contract limits — the same numbers Gurb's receiver enforces, so a mistake fails HERE. */
    public const SUB_MAX_LENGTH = 255;
    public const NAME_MAX_LENGTH = 200;
    public const AVATAR_URL_MAX_LENGTH = 2048;
    public const SNAPSHOT_PAGE_MAX_MEMBERS = 500;
    public const SNAPSHOT_MAX_PAGES = 1000;
    public const SNAPSHOT_ID_MAX_LENGTH = 200;
    public const SNAPSHOT_MAX_FUTURE_SECONDS = 5 * 60;
    public const SNAPSHOT_MAX_AGE_SECONDS = 72 * 60 * 60;

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const WHSEC_RE = '/^whsec_[A-Za-z0-9+\/]+={0,2}$/';
    private const CONTROL_RE = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u';

    private readonly string $communityId;
    /** @var list<string> */
    private readonly array $secrets;
    private readonly string $url;
    private readonly HttpClient $http;

    /**
     * @param string|list<string> $secret The events secret; during a rotation BOTH (newest first).
     */
    public function __construct(
        string $communityId,
        string|array $secret,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?HttpClient $httpClient = null,
        private readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ) {
        if (\preg_match(self::UUID_RE, $communityId) !== 1) {
            throw GurbApiException::localValidation('communityId must be the community UUID');
        }
        $secrets = \is_string($secret) ? [$secret] : \array_values($secret);
        if ($secrets === [] || \count($secrets) > 2) {
            throw GurbApiException::localValidation('pass one events secret, or two during a rotation');
        }
        foreach ($secrets as $s) {
            if (\is_string($s) && \str_starts_with($s, 'gurb_')) {
                // The two credentials must never be interchangeable.
                throw GurbApiException::localValidation('this is a Gurb API key, not an events secret (whsec_…) — they are separate credentials');
            }
            if (!\is_string($s) || \preg_match(self::WHSEC_RE, $s) !== 1 || \strlen(self::secretBytes($s)) < 24) {
                throw GurbApiException::localValidation('secret must be the community events secret, "whsec_<base64>"');
            }
        }

        $this->communityId = \strtolower($communityId);
        $this->secrets = $secrets;
        $this->url = \rtrim($baseUrl, '/') . '/api/webhooks/inbound/' . $this->communityId . '/host';
        $this->http = $httpClient ?? self::defaultHttpClient();
    }

    /** Where events go. */
    public function endpoint(): string
    {
        return $this->url;
    }

    // ─── builders ───────────────────────────────────────────────────────────

    /** A member's access changed. Send it on EVERY change, with the member's complete current access. */
    public function accessChanged(string $sub, int $stateVersion, string $access): PartnerEvent
    {
        return $this->build('access.changed', [
            'sub' => self::sub($sub),
            'state_version' => self::version($stateVersion),
            'access' => self::access($access),
        ]);
    }

    /** The member's login ended on your side (password reset, email change): end every Gurb session too. */
    public function sessionRevoked(string $sub, int $stateVersion, string $reason = 'credentials_changed'): PartnerEvent
    {
        return $this->build('member.session_revoked', [
            'sub' => self::sub($sub),
            'state_version' => self::version($stateVersion),
            'reason' => $reason,
        ]);
    }

    /**
     * The member's shared identity changed. A FULL REPLACEMENT: an omitted
     * field means "empty" — no email moves the member to a placeholder address
     * on Gurb, no avatarUrl removes their photo. Always send every field you share.
     */
    public function memberUpdated(
        string $sub,
        int $stateVersion,
        ?string $name = null,
        ?string $email = null,
        ?string $avatarUrl = null,
        ?string $locale = null,
    ): PartnerEvent {
        $data = ['sub' => self::sub($sub), 'state_version' => self::version($stateVersion)];
        $name = self::optionalText($name, 'name', self::NAME_MAX_LENGTH);
        if ($name !== null) {
            $data['name'] = (string) \preg_replace('/\s+/u', ' ', $name);
        }
        $email = self::email($email);
        if ($email !== null) {
            $data['email'] = $email;
        }
        $avatarUrl = self::avatarUrl($avatarUrl);
        if ($avatarUrl !== null) {
            $data['avatar_url'] = $avatarUrl;
        }
        if ($locale !== null) {
            if ($locale !== 'ar' && $locale !== 'en') {
                throw GurbApiException::localValidation('locale must be "ar" or "en"');
            }
            $data['locale'] = $locale;
        }

        return $this->build('member.updated', $data);
    }

    /** An approved erasure request: Gurb erases the member's personal data and forgets the link. Irreversible. */
    public function memberDeleted(string $sub, int $stateVersion, ?DateTimeInterface $requestedAt = null): PartnerEvent
    {
        return $this->build('member.deleted', [
            'sub' => self::sub($sub),
            'state_version' => self::version($stateVersion),
            'requested_at' => self::iso($requestedAt ?? new DateTimeImmutable()),
        ]);
    }

    /**
     * The nightly full list, split into pages of at most 500 (sharing one
     * snapshot_id and generated_at). List EVERY member account — once all pages
     * arrive, Gurb blocks the members it knows who are missing from the list
     * (unless that would block more than 10%: then a Gurb admin decides).
     *
     * @param list<array{sub: string, stateVersion: int, access: string}> $members
     *
     * @return list<PartnerEvent>
     */
    public function snapshot(
        array $members,
        ?string $snapshotId = null,
        ?DateTimeInterface $generatedAt = null,
        int $pageSize = self::SNAPSHOT_PAGE_MAX_MEMBERS,
    ): array {
        $generatedAt ??= new DateTimeImmutable();
        $age = \time() - $generatedAt->getTimestamp();
        if ($age < -self::SNAPSHOT_MAX_FUTURE_SECONDS || $age > self::SNAPSHOT_MAX_AGE_SECONDS) {
            throw GurbApiException::localValidation('generatedAt must be at most 5 minutes in the future and at most 72 hours old');
        }
        if ($pageSize < 1 || $pageSize > self::SNAPSHOT_PAGE_MAX_MEMBERS) {
            throw GurbApiException::localValidation('pageSize must be 1-' . self::SNAPSHOT_PAGE_MAX_MEMBERS);
        }

        $seen = [];
        $lines = [];
        foreach (\array_values($members) as $i => $m) {
            if (!\is_array($m) || !\is_string($m['sub'] ?? null) || !\is_int($m['stateVersion'] ?? null) || !\is_string($m['access'] ?? null)) {
                throw GurbApiException::localValidation("members[{$i}] needs sub (string), stateVersion (int) and access (string)");
            }
            $line = [
                'sub' => self::sub($m['sub'], "members[{$i}].sub"),
                'state_version' => self::version($m['stateVersion'], "members[{$i}].stateVersion"),
                'access' => self::access($m['access']),
            ];
            // A member listed twice is a bug in the export; Gurb would keep one silently.
            if (isset($seen[$line['sub']])) {
                throw GurbApiException::localValidation("members[{$i}].sub \"{$line['sub']}\" is listed twice");
            }
            $seen[$line['sub']] = true;
            $lines[] = $line;
        }

        $pages = \max(1, (int) \ceil(\count($lines) / $pageSize));
        if ($pages > self::SNAPSHOT_MAX_PAGES) {
            throw GurbApiException::localValidation('a snapshot may have at most ' . self::SNAPSHOT_MAX_PAGES . ' pages');
        }
        $id = $snapshotId ?? 'snap_' . \gmdate('Ymd\THis\Z') . '_' . \bin2hex(\random_bytes(4));
        if ($id === '' || \strlen($id) > self::SNAPSHOT_ID_MAX_LENGTH || \preg_match('/^[\x21-\x7e]+$/', $id) !== 1) {
            throw GurbApiException::localValidation('snapshotId must be printable ASCII without spaces, at most ' . self::SNAPSHOT_ID_MAX_LENGTH . ' characters');
        }
        $at = self::iso($generatedAt);

        $events = [];
        for ($p = 0; $p < $pages; $p++) {
            $events[] = $this->build('access.snapshot', [
                'snapshot_id' => $id,
                'generated_at' => $at,
                'page' => $p + 1,
                'pages' => $pages,
                'members' => \array_slice($lines, $p * $pageSize, $pageSize),
            ]);
        }

        return $events;
    }

    /** A connectivity and signature check ("Test connection"). Changes nothing. */
    public function ping(string $message = 'ping'): PartnerEvent
    {
        return $this->build('ping', ['sub' => null, 'state_version' => 0, 'message' => $message]);
    }

    // ─── send ───────────────────────────────────────────────────────────────

    /**
     * Deliver one built event. Never throws for an HTTP or network failure — the
     * result says what to do. Re-signed on every call; the id and body are the
     * stored ones, so Gurb applies a retried event once.
     */
    public function send(PartnerEvent $event): PartnerSendResult
    {
        try {
            /** @var mixed $parsed */
            $parsed = \json_decode($event->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw GurbApiException::localValidation('event body is not the JSON this client built');
        }
        // The body names its community: refuse to send one built for another.
        if (!\is_array($parsed) || ($parsed['id'] ?? null) !== $event->id || ($parsed['community'] ?? null) !== $this->communityId) {
            throw GurbApiException::localValidation('this event was built for a different community or has been altered');
        }

        $timestamp = \time();
        $request = new HttpRequest('POST', $this->url, [
            'Content-Type' => 'application/json',
            'webhook-id' => $event->id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::sign($this->secrets, $event->id, $timestamp, $event->body),
        ], $event->body, $this->timeoutMs);

        try {
            $response = $this->http->send($request);
        } catch (TransportException $e) {
            return new PartnerSendResult(PartnerSendResult::RETRY, 0, message: $e->getMessage());
        }

        $json = \json_decode($response->body, true);
        $json = \is_array($json) ? $json : [];
        $code = \is_string($json['code'] ?? null) ? $json['code'] : null;
        $message = \is_string($json['message'] ?? null) ? $json['message'] : null;
        $status = $response->status;

        if ($status >= 200 && $status < 300) {
            return new PartnerSendResult(PartnerSendResult::DELIVERED, $status, applied: \is_string($json['outcome'] ?? null) ? $json['outcome'] : null);
        }
        $outcome = ($status === 408 || $status === 429 || $status >= 500) ? PartnerSendResult::RETRY : PartnerSendResult::REJECTED;

        return new PartnerSendResult($outcome, $status, $code, null, $message);
    }

    /**
     * `v1,<sig>` per secret, space-separated (Standard Webhooks). Public for
     * tests and custom transports.
     *
     * @param list<string> $secrets
     */
    public static function sign(array $secrets, string $id, int $timestamp, string $body): string
    {
        return \implode(' ', \array_map(
            static fn (string $s): string => 'v1,' . \base64_encode(\hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", self::secretBytes($s), true)),
            $secrets,
        ));
    }

    // ─── internals ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function build(string $type, array $data): PartnerEvent
    {
        $id = 'evt_' . \bin2hex(\random_bytes(16));
        $createdAt = self::iso(new DateTimeImmutable());
        $body = \json_encode(
            ['id' => $id, 'type' => $type, 'created_at' => $createdAt, 'community' => $this->communityId, 'data' => $data],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );

        return new PartnerEvent($id, $type, $createdAt, $body);
    }

    private static function secretBytes(string $secret): string
    {
        return (string) \base64_decode(\substr($secret, \strlen('whsec_')), true);
    }

    private static function iso(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Characters, not bytes — without requiring ext-mbstring. */
    private static function length(string $value): int
    {
        return (int) \preg_match_all('/./su', $value);
    }

    /** Refuses invalid UTF-8 (PHP's form of broken Unicode) and control characters — Gurb would 400 them. */
    private static function storable(string $value, string $field): string
    {
        if (\preg_match('//u', $value) !== 1 || \preg_match(self::CONTROL_RE, $value) === 1) {
            throw GurbApiException::localValidation("{$field} contains a control character or invalid UTF-8");
        }

        return $value;
    }

    private static function sub(string $value, string $field = 'sub'): string
    {
        $trimmed = \trim(self::storable($value, $field));
        if ($trimmed === '' || self::length($trimmed) > self::SUB_MAX_LENGTH) {
            throw GurbApiException::localValidation("{$field} must be 1-" . self::SUB_MAX_LENGTH . ' characters');
        }

        return $trimmed;
    }

    private static function version(int $value, string $field = 'stateVersion'): int
    {
        if ($value < 0 || $value > 9007199254740991) {
            throw GurbApiException::localValidation("{$field} must be a non-negative integer (at most 2^53 − 1, a JSON-safe number)");
        }

        return $value;
    }

    private static function access(string $value): string
    {
        if ($value !== 'allowed' && $value !== 'blocked') {
            throw GurbApiException::localValidation('access must be "allowed" or "blocked"');
        }

        return $value;
    }

    private static function optionalText(?string $value, string $field, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = \trim(self::storable($value, $field));
        if ($trimmed === '') {
            return null;
        }
        if (self::length($trimmed) > $max) {
            throw GurbApiException::localValidation("{$field} must be at most {$max} characters");
        }

        return $trimmed;
    }

    private static function email(?string $value): ?string
    {
        $text = self::optionalText($value, 'email', 320);
        if ($text === null) {
            return null;
        }
        $at = \strpos($text, '@');
        if (\preg_match('/\s/u', $text) === 1 || $at === false || $at === 0 || $at !== \strrpos($text, '@') || $at === \strlen($text) - 1) {
            throw GurbApiException::localValidation('email is not a valid address');
        }
        if (\str_ends_with(\strtolower($text), '.embed.invalid')) {
            throw GurbApiException::localValidation('email is a Gurb placeholder address, not a real one');
        }

        return $text;
    }

    private static function avatarUrl(?string $value): ?string
    {
        $text = self::optionalText($value, 'avatarUrl', self::AVATAR_URL_MAX_LENGTH);
        if ($text === null) {
            return null;
        }
        $parts = \parse_url($text);
        // Gurb fetches photos over https on port 443 only; anything else would be
        // accepted, then silently never replace the member's photo.
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw GurbApiException::localValidation('avatarUrl must be https on the default port — Gurb fetches nothing else');
        }

        return $text;
    }

    private static function defaultHttpClient(): HttpClient
    {
        try {
            return new CurlHttpClient();
        } catch (TransportException $e) {
            throw new GurbApiException(
                'No HTTP transport available. The bundled CurlHttpClient needs ext-curl — install it, or pass your own HttpClient.',
                GurbErrorCode::NETWORK_ERROR,
                0,
                previous: $e,
            );
        }
    }
}
