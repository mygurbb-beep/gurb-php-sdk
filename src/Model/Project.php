<?php

declare(strict_types=1);

namespace Gurb\Model;

use Gurb\Internal\Decode;

/**
 * A project, from either projects module.
 *
 * ONE CLASS FOR BOTH `sdk/projects` AND `sdk/projects2`, because they publish
 * the same shape. See `Gurb\Resource\ProjectsResource` for why there are two
 * modules at all — the short version is that `projects2` is a parallel module,
 * not a newer version, and a community may run both.
 */
final class Project
{
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $coverUrl,
        /**
         * Free-form on the wire, so it is a nullable string and not an enum.
         *
         * The platform does not publish a closed set of project statuses, and
         * each community words its own. An enum here would throw on a value some
         * community typed last Tuesday — the same reason `Permission` is a class
         * of constants rather than an enum. Compare it if you must, but expect
         * strings you have not seen.
         */
        public readonly ?string $status,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Decode::str($data, 'id'),
            title: Decode::str($data, 'title'),
            description: Decode::nullableStr($data, 'description'),
            coverUrl: Decode::nullableStr($data, 'coverUrl'),
            status: Decode::nullableStr($data, 'status'),
            createdAt: Decode::str($data, 'createdAt'),
        );
    }
}
