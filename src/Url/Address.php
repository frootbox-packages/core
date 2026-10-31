<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A URL of a target in one language, current or earlier (decision 0105).
 * The path has no language prefix: "ueber-uns/team" for a page, "" for
 * the start page. Targets of plugins have a base page and the path below
 * it.
 */
final readonly class Address extends Entity
{
    public function __construct(
        public int $id,
        public string $target,
        public string $language,
        public ?string $basePageId,
        public string $path,
        public bool $current,
        public bool $fallback,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
