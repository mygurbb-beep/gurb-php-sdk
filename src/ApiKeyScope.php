<?php

declare(strict_types=1);

namespace Gurb;

/**
 * What a minted community key is allowed to do.
 *
 * Scope a key to `Read` unless the integration genuinely writes. Most do not,
 * and a read-only key that leaks is an incident you can close in an afternoon —
 * a write key that leaks is one you close by auditing everything it touched.
 * That is why `AdminApiKeysResource::create()` defaults to read-only and makes
 * you type `ApiKeyScope::Write` to ask for more.
 */
enum ApiKeyScope: string
{
    case Read = 'read';
    case Write = 'write';
}
