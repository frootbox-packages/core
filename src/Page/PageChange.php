<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * One change of a page in its history (decision 0174).
 *
 * kind is update, insert or delete; state says what changed: the draft,
 * the published page or an older version. language is set for content of
 * one language, block for a change in or of a block (the innermost one),
 * with its type. path leads to the value within the block, else within
 * the page or its translation, e.g. "fields/title", "title" or
 * "areas/main/#" for the order of the blocks in an area. Without old the
 * value was not there before, without new it is gone.
 */
final readonly class PageChange
{
    public function __construct(
        public string $kind,
        public string $state,
        public ?string $language,
        public ?string $block,
        public ?string $blockType,
        public string $path,
        public mixed $old = null,
        public mixed $new = null,
    ) {}
}
