<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

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

    private const array REPLACE = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'å' => 'a',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /**
     * Makes a slug from a title: "Über uns & Team" becomes
     * "ueber-uns-team". Empty if nothing usable is left.
     */
    public static function fromTitle(string $title): string
    {
        $slug = strtr(mb_strtolower($title), self::REPLACE);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
    }

    public static function isValid(string $slug): bool
    {
        return \strlen($slug) <= self::MAX_LENGTH && 1 === preg_match('/' . self::PATTERN . '/', $slug);
    }
}
