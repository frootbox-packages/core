<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

/**
 * The <head> of a page before it is written. Listeners of BuildingHead
 * change the title or add tags, e.g. meta descriptions or fonts.
 */
final class Head
{
    /** @var list<string> */
    private array $tags = [];

    /** @var array<string, string> */
    private array $alternates = [];

    /** One line for search engines, written as meta description (decision 0223) */
    public string $description = '';

    /** The title of the page or object alone for sharing, without the name of the website unless the page has its own title for search engines (decision 0266) */
    public ?string $name = null;

    /** The name of the website */
    public ?string $site = null;

    /**
     * An image of the object for sharing, the value of an image field
     *
     * @var array{media: string, alt?: string}|null
     */
    public ?array $image = null;

    /** The start page of a language */
    public bool $start = false;

    /**
     * @param string $direction "ltr" or "rtl" (decision 0133)
     */
    public function __construct(
        public string $language,
        public string $title,
        public ?string $canonical = null,
        public string $direction = 'ltr',
    ) {}

    /**
     * Adds a ready tag, e.g. '<meta name="robots" content="noindex">'.
     * Values in it must be escaped by the caller.
     */
    public function add(string $html): void
    {
        $this->tags[] = $html;
    }

    /**
     * The URL of this page in another language, for hreflang.
     */
    public function alternate(string $language, string $url): void
    {
        $this->alternates[$language] = $url;
    }

    /**
     * @return array<string, string> URL by language
     */
    public function alternates(): array
    {
        return $this->alternates;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->tags;
    }
}
