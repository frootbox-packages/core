<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Language;

/**
 * A language of the website, e.g. "de" with the title "Deutsch", and
 * where its URLs live (decision 0105).
 */
final readonly class Language
{
    /**
     * @param string      $prefix   The first path segment, e.g. "en"; empty for none
     * @param string|null $domain   A host of its own, e.g. "example.com"
     * @param string|null $fallback The language whose content is shown where a page has no translation
     */
    public function __construct(
        public string $code,
        public string $title,
        public string $prefix = '',
        public ?string $domain = null,
        public ?string $fallback = null,
    ) {}

    /**
     * The path of a page path in this language: "/en/about-us" for
     * "about-us", "/en" for the start page.
     */
    public function path(string $path): string
    {
        $path = trim($path, '/');

        if ('' === $this->prefix) {
            return '/' . $path;
        }

        return '/' . $this->prefix . ('' === $path ? '' : '/' . $path);
    }
}
