<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Support\Transliteration;
use Normalizer;

/**
 * The part of a page URL in one language, e.g. "ueber-uns" or "من-نحن".
 *
 * Latin, Cyrillic and Greek are written in a-z (decision 0134), other
 * scripts keep their letters (decision 0158).
 */
final class Slug
{
    /** A slug of a-z, 0-9 and hyphens, e.g. for language prefixes. */
    public const string LATIN_PATTERN = '^[a-z0-9]+(-[a-z0-9]+)*$';

    /** A letter or a mark of a script other than Latin, Cyrillic and Greek, in lower case. */
    private const string OTHER = '(?![\p{Latin}\p{Cyrillic}\p{Greek}\p{Lu}\p{Lt}])[\p{L}\p{M}]';

    public const string PATTERN = '^(?:[a-z0-9]|' . self::OTHER . ')+(?:-(?:[a-z0-9]|' . self::OTHER . ')+)*$';

    public const int MAX_LENGTH = 100;

    /**
     * Not allowed directly below the start page (decisions 0104, 0114),
     * nor the first segment of a route such as "cms".
     */
    public const array RESERVED = ['api', 'assets', 'media'];

    /** The first digit of each script, for digits that become 0-9. */
    private const array ZEROS = [0x0660, 0x06F0, 0x07C0, 0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66, 0x0BE6, 0x0C66, 0x0CE6, 0x0D66, 0x0DE6, 0x0E50, 0x0ED0, 0x0F20, 0x1040, 0x1090, 0x17E0, 0x1810, 0xFF10];

    /**
     * Makes a slug from a title by the rules of its language: "Über uns &
     * Team" in German becomes "ueber-uns-team", "Москва" "moskva", and
     * "من نحن" in Arabic "من-نحن".
     */
    public static function fromTitle(string $title, string $language = ''): string
    {
        $title = self::nfc(mb_scrub($title, 'UTF-8'));
        // Marks of the direction, joiners, the Arabic tatweel and the vowel marks of Arabic and
        // Hebrew go, so a word with and without them has one address; the half space of Persian parts words.
        $title = (string) preg_replace('/[\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200D}\x{0640}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0591}-\x{05BD}\x{05BF}\x{05C1}\x{05C2}\x{05C4}\x{05C5}\x{05C7}]/u', '', $title);
        $title = str_replace("\u{200C}", '-', $title);

        // Text of Latin, Cyrillic and Greek letters and everything that is no letter, in runs, so words keep their rules.
        $slug = (string) preg_replace_callback(
            '/(?:(?=[\p{Latin}\p{Cyrillic}\p{Greek}])\X|(?![\p{L}\p{M}])\X)+/u',
            static fn(array $run): string => Transliteration::toLatin($run[0], $language),
            $title,
        );
        $slug = self::nfc(mb_strtolower($slug, 'UTF-8'));
        $slug = (string) preg_replace_callback('/\p{Nd}/u', static fn(array $digit): string => self::digit($digit[0]), $slug);
        $slug = trim((string) preg_replace('/(?:(?![a-z0-9])(?!' . self::OTHER . ').)+/su', '-', $slug), '-');

        return rtrim(mb_substr($slug, 0, self::MAX_LENGTH, 'UTF-8'), '-');
    }

    /**
     * A slug of a-z, 0-9 and hyphens only, e.g. for the names of files.
     */
    public static function latin(string $title, string $language = ''): string
    {
        $slug = Transliteration::toLatin($title, $language);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
    }

    /**
     * Whether a slug is valid as it is, in NFC; see normalize() for input.
     */
    public static function isValid(string $slug): bool
    {
        return mb_check_encoding($slug, 'UTF-8')
            && mb_strlen($slug, 'UTF-8') <= self::MAX_LENGTH
            && 1 === preg_match('/' . self::PATTERN . '/u', $slug)
            && self::nfc($slug) === $slug;
    }

    public static function isLatin(string $slug): bool
    {
        return \strlen($slug) <= self::MAX_LENGTH && 1 === preg_match('/' . self::LATIN_PATTERN . '/', $slug);
    }

    /**
     * A slug as typed, in NFC, so letters split in two on a Mac pass.
     */
    public static function normalize(string $slug): string
    {
        return mb_check_encoding($slug, 'UTF-8') ? self::nfc($slug) : $slug;
    }

    private static function nfc(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return \is_string($normalized) ? $normalized : $text;
    }

    private static function digit(string $digit): string
    {
        $code = mb_ord($digit, 'UTF-8');

        foreach (self::ZEROS as $zero) {
            if ($code >= $zero && $code <= $zero + 9) {
                return (string) ($code - $zero);
            }
        }

        return ctype_digit($digit) ? $digit : '-';
    }
}
