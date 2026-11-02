<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Language;

/**
 * A language of the website, e.g. "de" with the title "Deutsch", and
 * where its URLs live (decision 0105).
 */
final readonly class Language
{
    public Direction $direction;

    /** The script as a code of ISO 15924, e.g. "arab" (decision 0159) */
    public string $script;

    /**
     * @param string      $prefix   The first path segment, e.g. "en"; empty for none
     * @param string|null $domain   A host of its own, e.g. "example.com"
     * @param string|null    $fallback  The language whose content is shown where a page has no translation
     * @param Direction|null $direction From the code if not given, e.g. right to left for "ar" (decision 0133)
     * @param string|null    $script    From the code if not given, e.g. "arab" for "ar" (decision 0159)
     */
    public function __construct(
        public string $code,
        public string $title,
        public string $prefix = '',
        public ?string $domain = null,
        public ?string $fallback = null,
        ?Direction $direction = null,
        ?string $script = null,
    ) {
        $this->direction = $direction ?? Direction::of($code);
        $this->script = $script ?? Script::of($code);
    }

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
