<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

/**
 * A type of objects of a module that pages show (decisions 0027, 0219,
 * 0232), e.g. news articles or jobs. Registered with
 * $registry->plugin(ArticlePlugin::class).
 *
 * The objects belong to spaces: always the default one, more if the
 * plugin allows them. An admin assigns a space to a page, the layout
 * shows the view of the plugin at plugin(). With details on, every
 * published object gets an address below that page, e.g.
 * /karriere/jobs/koch-m-w-d. The core keeps these addresses in the table
 * urls: an address that changes redirects (301), the paths follow when
 * the page moves. A plugin action that publishes, changes or deletes an
 * object calls AddressSync::sync() afterwards, like page.publish does.
 */
interface Plugin
{
    /**
     * The type of the targets, e.g. "acme/news/article".
     */
    public function type(): string;

    /**
     * The name for editors in English, translated like any text, e.g. "News".
     */
    public function label(): string;

    /**
     * The block that shows the plugin at plugin(), e.g. "acme/news/ArticleList".
     * Its view has "plugin: true" in its head and is not placed by editors.
     */
    public function block(): string;

    /**
     * Whether the objects get pages of their own below the page of their space.
     */
    public function details(): Offer;

    /**
     * Whether the categories of the type (decision 0233) get pages of
     * their own below the page of a space, e.g. /news/presse (decision
     * 0243). Unlike details off unless the admin turns them on.
     */
    public function categories(): Offer;

    /**
     * Whether an admin may add spaces besides the default one.
     */
    public function spaces(): bool;

    /**
     * The published objects of a space with their path below the page in
     * each language they have, e.g. "01J…" => ["de" => "neue-sorten"].
     *
     * @return iterable<string, array<string, string>>
     */
    public function paths(string $space): iterable;

    /**
     * How many objects a space holds, drafts included. A space with
     * objects cannot be deleted.
     */
    public function count(string $space): int;

    /**
     * The title of a published object in a language, e.g. for the title
     * of the page. Null if it has none there.
     */
    public function title(string $id, string $language): ?string;

    /**
     * One line for search engines and sharing (decision 0223), null or
     * empty if the object has none in this language.
     */
    public function description(string $id, string $language): ?string;

    /**
     * The value of an image field for sharing, null without an image.
     *
     * @return array{media: string, alt?: string}|null
     */
    public function image(string $id, string $language): ?array;
}
