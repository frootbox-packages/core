<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Frootbox\Core\Support\Transliteration;

/**
 * Text as the index compares it (decision 0214): lower case Latin letters
 * where a script has rules (decision 0134), so "kyiv" finds "Київ" and
 * "istanbul" finds "İstanbul"; Arabic and Hebrew without vowel marks and
 * with one form of alef, so spellings meet. Everything else that is no
 * letter or digit becomes a space.
 */
final class SearchTerms
{
    /** Arabic vowel marks and tatweel, Hebrew points. */
    private const string MARKS = '/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{0591}-\x{05C7}]/u';

    private const array LETTERS = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي'];

    /**
     * The words of a text, by the rules of its language and by the
     * general ones, each once.
     *
     * @return list<string>
     */
    public static function words(string $text, string $language = ''): array
    {
        $words = [];

        foreach (array_unique(['' === $language ? null : self::normalize($text, $language), self::normalize($text, '')]) as $normalized) {
            foreach (null === $normalized ? [] : explode(' ', $normalized) as $word) {
                if ('' !== $word) {
                    $words[$word] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($words));
    }

    /**
     * The text for the column "terms": its words, one space apart and
     * with a space at both ends, so a search can ask for word starts.
     */
    public static function column(string $text, string $language = ''): string
    {
        return ' ' . implode(' ', self::words($text, $language)) . ' ';
    }

    public static function normalize(string $text, string $language = ''): string
    {
        $text = (string) preg_replace(self::MARKS, '', $text);
        $text = strtr($text, self::LETTERS);
        $text = mb_strtolower(Transliteration::toLatin($text, $language));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
