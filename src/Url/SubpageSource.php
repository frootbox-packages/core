<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

/**
 * Objects of a plugin with addresses below a page, e.g. news articles
 * at /news/<slug> (decisions 0105, 0219). A page becomes their base page
 * when it holds one of the blocks. Registered with
 * $registry->subpages(ArticlePages::class).
 *
 * The core keeps their addresses in the table urls: an address that
 * changes redirects (301), the paths follow when the base page moves.
 * A plugin action that publishes, changes or deletes an object calls
 * AddressSync::sync() afterwards, like page.publish does.
 */
interface SubpageSource
{
    /**
     * The type of the targets, e.g. "acme/news/article".
     */
    public function type(): string;

    /**
     * The blocks that make a page the base page, e.g.
     * ["acme/news/ArticleList"]. The first page in the tree with one is
     * the base page in a language.
     *
     * @return list<string>
     */
    public function blocks(): array;

    /**
     * The published objects with their path below the base page in each
     * language they have, e.g. "01J…" => ["de" => "neue-sorten"].
     *
     * @return iterable<string, array<string, string>>
     */
    public function paths(): iterable;

    /**
     * The title of a published object in a language, e.g. for the title
     * of the page. Null if it has none there.
     */
    public function title(string $id, string $language): ?string;
}
