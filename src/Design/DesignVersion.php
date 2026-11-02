<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use DateTimeImmutable;

/**
 * A published version of the design, to look back or restore it.
 */
final readonly class DesignVersion
{
    public function __construct(
        public int $version,
        public bool $current,
        public DateTimeImmutable $publishedAt,
        public ?int $authorId,
        public ?string $channel,
    ) {}
}
