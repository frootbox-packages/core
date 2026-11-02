<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Heading;

/**
 * A heading as heading() or rich text rendered it.
 *
 * @internal
 */
final readonly class RenderedHeading
{
    public function __construct(
        public int $level,
        public int $wanted,
        public string $text,
        public ?string $block,
        public ?string $field,
        public ?int $rank,
    ) {}
}
