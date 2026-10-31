<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;

/**
 * A published version of a page in the result of page.versions.
 */
final readonly class PageVersion
{
    public function __construct(
        public int $version,
        public bool $current,
        public DateTimeImmutable $publishedAt,
        public ?int $authorId,
        public ?string $channel,
    ) {}
}
