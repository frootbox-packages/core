<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

/**
 * A search source that also describes what is published, for the search
 * on the website (decision 0228), e.g. pages, articles and events. Only
 * things with an address of their own belong here.
 */
interface PublishedSource extends SearchSource
{
    /**
     * The published entries of these items, one per language. An item
     * without a published version yields none and leaves the index.
     *
     * @param list<string> $items
     *
     * @return iterable<PublishedEntry>
     */
    public function published(array $items): iterable;
}
