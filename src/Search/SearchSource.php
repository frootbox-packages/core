<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

/**
 * Describes things to find for the global search (decision 0214), e.g.
 * pages or articles. The index asks it again for the items whose rows an
 * action changed, so it stays current without anyone doing anything.
 */
interface SearchSource
{
    /**
     * The items a changed row belongs to, empty if it does not concern
     * this source.
     *
     * @param array<string, mixed> $row The values after the change, or before a delete
     *
     * @return list<string>
     */
    public function changed(string $table, array $row): array;

    /**
     * All items, for building the index anew.
     *
     * @return iterable<string>
     */
    public function items(): iterable;

    /**
     * The entries of these items, as editors see them: drafts first. An
     * item without entries leaves the index.
     *
     * @param list<string> $items
     *
     * @return iterable<SearchEntry>
     */
    public function entries(array $items): iterable;
}
