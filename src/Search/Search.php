<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Auth\ScopeResolvers;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Theme\ThemeChain;

/**
 * The global search of the CMS (decision 0214): everything the user may
 * see, drafts included, best hits first. Each word must occur, in any
 * spelling ("kyiv" finds "Київ"); a hit counts once with all languages
 * it was found in.
 *
 * @implements Query<list<SearchHit>>
 */
#[AsQuery('search.find', 'Finds pages, media, users and everything else the user may see by words, drafts included, best hits first', signedIn: true)]
final readonly class Search implements Query
{
    /** Lines of the text shown around the first word found, at most this long. */
    private const int EXCERPT = 160;

    /**
     * @param list<string>|null $sources
     */
    public function __construct(
        #[Length(1, 200)]
        public string $q,
        #[ListOf('string')]
        #[Description('Only these sources, e.g. ["page", "medium"]; none for all')]
        public ?array $sources = null,
        #[Range(1, 50)]
        public int $limit = 20,
    ) {}

    /**
     * @return list<SearchHit>
     */
    public function handle(SearchIndex $index, SearchIndexer $indexer, SearchSources $sources, Gate $gate, Catalog $catalog, ScopeResolvers $resolvers, CurrentContext $context, Translator $translator, ThemeChain $themes): array
    {
        $words = self::words($this->q, $translator->language());

        if ([] === $words) {
            return [];
        }

        // A new installation or an emptied index fills itself once.
        if ($index->isEmpty()) {
            $indexer->rebuild();
        }

        $known = $sources->all();
        $wanted = null === $this->sources ? null : array_values(array_intersect($this->sources, array_keys($known)));
        $rows = self::allowed($index->candidates($words, $wanted), $gate, $catalog, $resolvers, $context);
        $themeNames = array_map(static fn($theme): string => $theme->name, $themes->themes);
        $hits = [];

        foreach ($rows as $row) {
            $key = $row['source'] . "\0" . $row['item'];
            $score = self::score($row, $words);
            $hit = $hits[$key] ?? null;

            if (null !== $hit && $hit['score'] >= $score) {
                $hit['languages'][] = $row['language'];
                $hits[$key] = $hit;

                continue;
            }

            $hits[$key] = ['row' => $row, 'score' => $score, 'languages' => [$row['language'], ...($hit['languages'] ?? [])]];
        }

        uasort($hits, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        $result = [];

        foreach (\array_slice($hits, 0, $this->limit) as $hit) {
            $row = $hit['row'];
            $source = $known[$row['source']] ?? null;
            $package = self::package($row['source']);

            $result[] = new SearchHit(
                source: $row['source'],
                sourceTitle: null === $source ? $row['source'] : $translator->label($source->title, $package),
                item: $row['item'],
                title: (bool) $row['label'] ? $translator->label($row['title'], [...(null === $package ? [] : [$package]), ...$themeNames]) : $row['title'],
                excerpt: self::excerpt($row['body'], $words, $this->q),
                language: $row['language'],
                languages: array_values(array_unique(array_filter($hit['languages'], static fn(string $language): bool => '' !== $language))),
                score: $hit['score'],
            );
        }

        return $result;
    }

    /**
     * The words of the input, each with its spellings.
     *
     * @return list<list<string>>
     */
    private static function words(string $input, string $language): array
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
     * Only the rows the user may see: the permission of their query, and
     * with a role in branches only those within (decision 0118).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{source: string, item: string, language: string, title: string, body: string, terms: string, label: bool|int|string}>
     */
    private static function allowed(array $rows, Gate $gate, Catalog $catalog, ScopeResolvers $resolvers, CurrentContext $context): array
    {
        $actor = $context->get()->actor;
        $byPermission = [];

        foreach ($rows as $row) {
            $byPermission[\is_string($row['permission'] ?? null) ? $row['permission'] : ''][] = $row;
        }

        $allowed = [];

        foreach ($byPermission as $permission => $group) {
            $descriptor = $catalog->has($permission) ? $catalog->get($permission) : null;

            if (null === $descriptor || !$gate->allows($actor, $descriptor)) {
                continue;
            }

            $byKind = [];

            foreach ($group as $row) {
                $kind = $row['scope_kind'] ?? null;

                if (\is_string($kind) && \is_string($row['scope_id'] ?? null)) {
                    $byKind[$kind][] = $row;
                } else {
                    $allowed[] = $row;
                }
            }

            foreach ($byKind as $kind => $scoped) {
                $within = $gate->within($actor, $descriptor->permission, $kind);
                $ids = null === $within ? null : $resolvers->filter($kind, $within, array_values(array_unique(array_map(static fn(array $row): string => $row['scope_id'], $scoped))));

                foreach ($scoped as $row) {
                    if (null === $ids || \in_array($row['scope_id'], $ids, true)) {
                        $allowed[] = $row;
                    }
                }
            }
        }

        /** @var list<array{source: string, item: string, language: string, title: string, body: string, terms: string, label: bool|int|string}> */
        return array_values(array_filter($allowed, static fn(array $row): bool => \is_string($row['source'] ?? null) && \is_string($row['item'] ?? null)));
    }

    /**
     * The whole title before its start before a word start in it before
     * the text; within each, word starts count more than parts of words.
     *
     * @param array{title: string, terms: string} $row
     * @param list<list<string>>                  $words
     */
    private static function score(array $row, array $words): int
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
     * The line of the text with the first word found, cut around it.
     *
     * @param list<list<string>> $words
     */
    private static function excerpt(string $body, array $words, string $input): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $body)), static fn(string $line): bool => '' !== $line));
        $found = null;

        foreach ($lines as $line) {
            $normalized = SearchTerms::normalize($line);

            if (array_any($words, static fn(array $spellings): bool => array_any($spellings, static fn(string $word): bool => str_contains($normalized, $word)))) {
                $found = $line;

                break;
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

    /**
     * The package of a source of an extension, e.g. "acme/news" for
     * "acme/news/article"; null for the core.
     */
    private static function package(string $source): ?string
    {
        $slash = strrpos($source, '/');

        return false === $slash ? null : substr($source, 0, $slash);
    }
}
