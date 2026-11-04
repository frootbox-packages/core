<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * Title, slug, content, description and title for search engines of a
 * page in one language.
 */
final readonly class TranslationData
{
    /**
     * @param array<string, mixed> $content Options, fields and areas of the layout
     */
    public function __construct(
        public string $title,
        public string $slug,
        public array $content = [],
        /** For search engines and sharing, one line (decision 0223) */
        public string $description = '',
        /** The whole title in search results instead of the default one, or empty (decision 0266) */
        public string $seoTitle = '',
    ) {}

    /**
     * The same with other content.
     *
     * @param array<string, mixed> $content
     */
    public function withContent(array $content): self
    {
        return new self($this->title, $this->slug, $content, $this->description, $this->seoTitle);
    }

    /**
     * The title in search results and in the browser: its own or the default
     * one (decision 0266).
     *
     * @param bool $start Whether it is the start page of its language
     */
    public function searchTitle(string $site, bool $start): string
    {
        return '' === $this->seoTitle ? self::defaultTitle($this->title, $site, $start) : $this->seoTitle;
    }

    /**
     * The title in search results without one of its own: the name of the
     * website on the start page, else the title with it (decision 0223).
     */
    public static function defaultTitle(string $title, string $site, bool $start): string
    {
        return $start ? $site : $title . ' – ' . $site;
    }
}
