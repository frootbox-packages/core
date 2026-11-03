<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Frootbox\Core\Url\Target;

/**
 * Something visitors can find on the website (decision 0228): the
 * published page or object in one language and where it leads. Whether
 * a visitor may see it is decided when searching, by the address of the
 * target and the page it lies on.
 */
final readonly class PublishedEntry
{
    public function __construct(
        public string $item,
        public string $language,
        public string $title,
        public string $text,
        public Target $target,
    ) {}
}
