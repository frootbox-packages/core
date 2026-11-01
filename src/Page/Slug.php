<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Support\Transliteration;

/**
 * The part of a page URL in one language, e.g. "ueber-uns".
 */
final class Slug
{
    public const string PATTERN = '^[a-z0-9]+(-[a-z0-9]+)*$';

    public const int MAX_LENGTH = 100;

    /**
     * Not allowed directly below the start page (decisions 0104, 0114),
     * nor the first segment of a route such as "cms".
     */
    public const array RESERVED = ['api', 'assets', 'media'];

    /**
     * Makes a slug from a title by the rules of its language (decision
     * 0134): "Über uns & Team" in German becomes "ueber-uns-team",
     * "Київ" in Ukrainian "kyiv". Empty if nothing usable is left.
     */
    public static function fromTitle(string $title, string $language = ''): string
    {
        $slug = Transliteration::toLatin($title, $language);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
    }

    public static function isValid(string $slug): bool
    {
        return \strlen($slug) <= self::MAX_LENGTH && 1 === preg_match('/' . self::PATTERN . '/', $slug);
    }
}
