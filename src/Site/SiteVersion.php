<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use DateTimeImmutable;

/**
 * A published version of the fields of the website, to look back or
 * restore it (decision 0348).
 */
final readonly class SiteVersion
{
    public function __construct(
        public int $version,
        /** Whether visitors see this one */
        public bool $current,
        public DateTimeImmutable $publishedAt,
        public ?int $authorId,
        public ?string $channel,
    ) {}
}
