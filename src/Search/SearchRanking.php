<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

/**
 * How hits are found and ordered (decisions 0214, 0217), the same for
 * the global search and the search on the website (decision 0228).
 */
final class SearchRanking
{
    /** Lines of the text shown around the first word found, at most this long. */
    public const int EXCERPT = 160;

    /**
     * The words of the input, each with its spellings.
     *
     * @return list<list<string>>
     */
    public static function words(string $input, string $language): array
    {
        $words = [];

        $split = preg_split('/[^\p{L}\p{N}]+/u', $input, flags: \PREG_SPLIT_NO_EMPTY);

        foreach (false === $split ? [] : $split as $word) {
            // The general spelling and that of the language of the user, e.g. "groesse" and "grosse" for "Größe".
            $spellings = array_values(array_unique(array_filter([SearchTerms::normalize($word), SearchTerms::normalize($word, $language)], static fn(string $spelling): bool => '' !== $spelling)));

            if ([] !== $spellings) {
                $words[] = $spellings;
            }
        }

        return $words;
    }

    /**
     * The whole title before its start before a word start in it before
     * the text; within each, word starts count more than parts of words.
     *
     * @param array{title: string, terms: string} $row
     * @param list<list<string>>                  $words
     */
    public static function score(array $row, array $words): int
    {
        $title = ' ' . SearchTerms::normalize($row['title']) . ' ';
        $query = implode(' ', array_map(static fn(array $spellings): string => $spellings[0], $words));
        $score = match (true) {
            trim($title) === $query => 100,
            str_starts_with($title, ' ' . $query) => 60,
            default => 0,
        };

        foreach ($words as $spellings) {
            $score += match (true) {
                array_any($spellings, static fn(string $word): bool => str_contains($title, ' ' . $word)) => 20,
                array_any($spellings, static fn(string $word): bool => str_contains($title, $word)) => 12,
                array_any($spellings, static fn(string $word): bool => str_contains($row['terms'], ' ' . $word)) => 4,
                default => 1,
            };
        }

        return $score;
    }

    /**
     * The line of the text with the most words found, cut around the first.
     *
     * @param list<list<string>> $words
     */
    public static function excerpt(string $body, array $words, string $input): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $body)), static fn(string $line): bool => '' !== $line));
        $found = null;
        $most = 0;

        // The first line with the most words, e.g. the text rather than the address.
        foreach ($lines as $line) {
            $normalized = SearchTerms::normalize($line);
            $count = \count(array_filter($words, static fn(array $spellings): bool => array_any($spellings, static fn(string $word): bool => str_contains($normalized, $word))));

            if ($count > $most) {
                [$found, $most] = [$line, $count];
            }
        }

        $line = $found ?? $lines[0] ?? '';

        if (mb_strlen($line) <= self::EXCERPT) {
            return $line;
        }

        $first = preg_split('/\s+/u', trim($input))[0] ?? '';
        $at = '' === $first ? false : mb_stripos($line, $first);
        $start = false === $at ? 0 : max(0, $at - (int) (self::EXCERPT / 3));
        $cut = mb_substr($line, $start, self::EXCERPT);

        return ($start > 0 ? '… ' : '') . trim($cut) . ($start + self::EXCERPT < mb_strlen($line) ? ' …' : '');
    }
}
