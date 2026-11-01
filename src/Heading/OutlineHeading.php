<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Heading;

/**
 * A heading of a page as visitors get it (decision 0129).
 */
final readonly class OutlineHeading
{
    /** The core gave it another level, so no level is skipped. */
    public const string ADJUSTED = 'adjusted';

    /** A template writes the level, e.g. in the footer. */
    public const string FIXED = 'fixed';

    /** A fixed level skips a level. */
    public const string SKIPS = 'skips';

    /** A fixed level makes a second h1. */
    public const string SECOND_MAIN = 'secondMain';

    /** The page has no h1 of its own; its title is one for screen readers. */
    public const string ADDED = 'added';

    /**
     * @param int<1, 6>     $level
     * @param int|null      $rank    0 for the main heading of a block, 1 and deeper below it; null if fixed
     * @param string|null   $blockId The block it belongs to, null for the layout or a template
     * @param string|null   $field   Path of its field in the block or layout, e.g. "items/0/question"
     * @param list<string>  $notes   What to know about it, see the constants
     */
    public function __construct(
        public int $level,
        public string $text,
        public ?int $rank = null,
        public ?string $blockId = null,
        public ?string $field = null,
        public array $notes = [],
    ) {}
}
