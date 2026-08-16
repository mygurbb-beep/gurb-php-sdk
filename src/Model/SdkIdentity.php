<?php

declare(strict_types=1);

namespace Gurb\Model;

/**
 * WHO YOUR KEY ACTS FOR — the first call an integration should make.
 *
 * WHY THIS EXISTS AT ALL
 *
 * A platform key can only be MINTED by a Gurb super admin. Before keys could
 * name a subject, that meant every community created through the SDK was founded
 * by the platform admin — a customer handed a key to build their own community
 * founded nothing, while the admin's account silently collected communities they
 * had never heard of. Nobody noticed until somebody asked why their community
 * belonged to someone else.
 *
 * So: call this BEFORE anything irreversible. If `actsForIssuer` is true, the key
 * you were given has no subject, and everything you create will belong to the
 * platform admin rather than to you. Ask for a new key rather than proceeding.
 *
 * WHAT IT DOES NOT TELL YOU
 *
 * Nothing about permissions. A 200 means the key is live and names a real,
 * active account. It does not mean that account may do any particular thing —
 * permissions are checked per operation, and differ per section by design.
 */
final class SdkIdentity
{
    private function __construct(
        /** The account communities created with this key are founded by. */
        public readonly ?SdkPerson $subject,
        /** The platform admin who minted the key. Always present. */
        public readonly SdkPerson $issuedBy,
        /**
         * True when the key names no subject, so it acts for its minter.
         *
         * On a key somebody handed you, this is a red flag rather than a
         * detail: what you create will not be yours.
         */
        public readonly bool $actsForIssuer,
        public readonly ?string $keyId,
        public readonly ?string $keyName,
        /** @var list<string> */
        public readonly array $scopes,
    ) {
    }

    /** The account that will own what you create — subject if named, else the issuer. */
    public function effectiveOwner(): SdkPerson
    {
        return $this->subject ?? $this->issuedBy;
    }

    /** True when this key may write. A read-only key publishes nothing. */
    public function canWrite(): bool
    {
        return \in_array('write', $this->scopes, true);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $key = \is_array($data['key'] ?? null) ? $data['key'] : [];
        $scopes = \is_array($key['scopes'] ?? null) ? $key['scopes'] : [];

        return new self(
            subject: \is_array($data['subject'] ?? null)
                ? SdkPerson::fromArray($data['subject'])
                : null,
            issuedBy: SdkPerson::fromArray(
                \is_array($data['issuedBy'] ?? null) ? $data['issuedBy'] : [],
            ),
            // Defaults to TRUE when absent, which is the cautious direction: an
            // older server that does not send the field is one where subjects do
            // not exist, and on such a server the key really does act for its
            // issuer.
            actsForIssuer: (bool) ($data['actsForIssuer'] ?? true),
            keyId: isset($key['id']) && \is_string($key['id']) ? $key['id'] : null,
            keyName: isset($key['name']) && \is_string($key['name']) ? $key['name'] : null,
            scopes: \array_values(\array_filter($scopes, \is_string(...))),
        );
    }
}
