<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Support;

use Normalizer;

/**
 * Writes text in lower case Latin letters by the rules of a language
 * (decision 0134): "Київ" in Ukrainian becomes "kyiv", "Größe" in German
 * "groesse", "İstanbul" "istanbul". Scripts without rules (Chinese,
 * Arabic) stay as they are, spaces and punctuation too.
 *
 * Own tables instead of ICU, so every server gives the same result.
 */
final class Transliteration
{
    /**
     * Rules of single languages, before the general ones.
     */
    private const array LANGUAGES = [
        'de' => ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue'],
        'da' => self::NORDIC,
        'nb' => self::NORDIC,
        'nn' => self::NORDIC,
        'no' => self::NORDIC,
        'hr' => ['đ' => 'dj'],
        'bs' => ['đ' => 'dj'],
        // Official Serbian Latin script without its marks
        'sr' => [
            'đ' => 'dj', 'ђ' => 'dj', 'ж' => 'z', 'ј' => 'j', 'љ' => 'lj', 'њ' => 'nj', 'ћ' => 'c',
            'х' => 'h', 'ц' => 'c', 'ч' => 'c', 'џ' => 'dz', 'ш' => 's',
        ],
        // Official system of 2010; the forms at the start of a word in ukrainian()
        'uk' => [
            'г' => 'h', 'ґ' => 'g', 'є' => 'ie', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i',
            'ю' => 'iu', 'я' => 'ia',
        ],
        // Official system of 2009; "ия" at the end of a word in bulgarian()
        'bg' => ['х' => 'h', 'щ' => 'sht', 'ъ' => 'a', 'ь' => 'y'],
        // Official system of Macedonia
        'mk' => [
            'ѓ' => 'gj', 'ѕ' => 'dz', 'ј' => 'j', 'љ' => 'lj', 'њ' => 'nj', 'ќ' => 'kj', 'х' => 'h',
            'ц' => 'c', 'џ' => 'dj',
        ],
    ];

    private const array NORDIC = ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa'];

    private const array UKRAINIAN_INITIAL = ['є' => 'ye', 'ї' => 'yi', 'й' => 'y', 'ю' => 'yu', 'я' => 'ya'];

    /**
     * Greek accents go before the pairs of letters; the diaeresis keeps
     * a vowel apart ("αϋ" is "ay", not "av").
     */
    private const array GREEK_ACCENTS = [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
        'ϊ' => 'i', 'ϋ' => 'y', 'ΐ' => 'i', 'ΰ' => 'y',
    ];

    /**
     * For every language: Latin letters of their own, Cyrillic as in
     * Russian practice plus the letters of other languages, Greek as in
     * ELOT 743, apostrophes and the Catalan middle dot. Letters with
     * marks lose them afterwards ("ş" to "s").
     */
    private const array GENERAL = [
        // Latin
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'đ' => 'd', 'ł' => 'l', 'ŀ' => 'l', 'ı' => 'i',
        'þ' => 'th', 'ð' => 'd', 'ħ' => 'h', 'ŧ' => 't', 'ə' => 'e', 'ĸ' => 'k', 'ŋ' => 'n',
        "'" => '', '’' => '', 'ʼ' => '', '‘' => '', '`' => '', '´' => '', '·' => '',
        // Cyrillic
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g', 'ў' => 'u', 'ј' => 'j', 'љ' => 'lj',
        'њ' => 'nj', 'ђ' => 'dj', 'ћ' => 'c', 'џ' => 'dz', 'ѓ' => 'gj', 'ќ' => 'kj', 'ѕ' => 'dz',
        // Greek
        'ου' => 'ou', 'αυ' => 'av', 'ευ' => 'ev', 'ηυ' => 'iv', 'γγ' => 'ng', 'γκ' => 'gk', 'γξ' => 'nx',
        'γχ' => 'nch', 'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i',
        'θ' => 'th', 'ι' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o',
        'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'ch',
        'ψ' => 'ps', 'ω' => 'o',
    ];

    /**
     * @param string $language a code such as "de" or "de-at"; empty for the general rules only
     */
    public static function toLatin(string $text, string $language = ''): string
    {
        $text = self::normalize(mb_scrub($text, 'UTF-8'), Normalizer::FORM_C);
        // mb_strtolower() makes "İ" an "i" with a dot of its own.
        $text = mb_strtolower(str_replace('İ', 'i', $text), 'UTF-8');
        $code = strtolower(explode('-', str_replace('_', '-', $language))[0]);

        $text = match ($code) {
            'uk' => self::ukrainian($text),
            'bg' => (string) preg_replace('/ия(?!\p{L})/u', 'ia', $text),
            default => $text,
        };

        $text = strtr($text, self::LANGUAGES[$code] ?? []);
        $text = strtr(strtr($text, self::GREEK_ACCENTS), self::GENERAL);

        return (string) preg_replace('/\p{Mn}+/u', '', self::normalize($text, Normalizer::FORM_D));
    }

    /**
     * Normalizer from intl, else from symfony/polyfill-intl-normalizer;
     * the text as it is if it cannot be normalized.
     */
    private static function normalize(string $text, int $form): string
    {
        $normalized = Normalizer::normalize($text, $form);

        return \is_string($normalized) ? $normalized : $text;
    }

    private static function ukrainian(string $text): string
    {
        $text = str_replace('зг', 'zgh', $text);

        return (string) preg_replace_callback(
            "/(?<![\\p{L}'’ʼ])[єїйюя]/u",
            static fn(array $match): string => self::UKRAINIAN_INITIAL[$match[0]] ?? $match[0],
            $text,
        );
    }
}
