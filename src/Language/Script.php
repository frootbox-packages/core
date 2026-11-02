<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Language;

/**
 * The script a language is written in, as a code of ISO 15924 in lower
 * case, e.g. "arab" for "ar". Themes give scripts their own fonts and
 * tokens (decision 0159).
 */
final class Script
{
    public const string PATTERN = '/^[a-z]{4}$/';

    /** Scripts other than Latin, by the first part of the language code. */
    private const array SCRIPTS = [
        'arab' => ['ar', 'ckb', 'fa', 'ks', 'ps', 'sd', 'ug', 'ur'],
        'armn' => ['hy'],
        'beng' => ['as', 'bn'],
        'cyrl' => ['be', 'bg', 'kk', 'ky', 'mk', 'mn', 'ru', 'sr', 'tg', 'tt', 'uk'],
        'deva' => ['hi', 'mr', 'ne', 'sa'],
        'ethi' => ['am', 'ti'],
        'geor' => ['ka'],
        'grek' => ['el'],
        'gujr' => ['gu'],
        'guru' => ['pa'],
        'hans' => ['zh'],
        'hebr' => ['he', 'yi'],
        'jpan' => ['ja'],
        'khmr' => ['km'],
        'knda' => ['kn'],
        'kore' => ['ko'],
        'laoo' => ['lo'],
        'mlym' => ['ml'],
        'mymr' => ['my'],
        'orya' => ['or'],
        'sinh' => ['si'],
        'taml' => ['ta'],
        'telu' => ['te'],
        'thaa' => ['dv'],
        'thai' => ['th'],
        'tibt' => ['bo'],
    ];

    /** Codes such as "zh-tw" or "zh-hant" are written in traditional characters. */
    private const array TRADITIONAL = ['hk', 'mo', 'tw'];

    /**
     * The script of a language code: one named in the code ("sr-latn"),
     * else the usual one ("cyrl" for "sr"), else "latn".
     */
    public static function of(string $code): string
    {
        $parts = explode('-', strtolower($code));

        foreach (\array_slice($parts, 1) as $part) {
            if (1 === preg_match(self::PATTERN, $part)) {
                return $part;
            }
        }

        if ('zh' === $parts[0] && [] !== array_intersect(\array_slice($parts, 1), self::TRADITIONAL)) {
            return 'hant';
        }

        foreach (self::SCRIPTS as $script => $languages) {
            if (\in_array($parts[0], $languages, true)) {
                return $script;
            }
        }

        return 'latn';
    }
}
