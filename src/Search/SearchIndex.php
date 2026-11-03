<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Journal;

/**
 * The table "search_index" (decision 0214): one row per thing and
 * language. It is bookkeeping like the action log, derived from the
 * content and written directly, never recorded for undoing; an undone
 * action is an action of its own and updates it again.
 */
final readonly class SearchIndex
{
    public const string TABLE = 'search_index';

    /** What editors see, drafts included. */
    public const string DRAFT = 'draft';

    /** What visitors find on the website (decision 0228). */
    public const string LIVE = 'live';

    /** At most this many rows are ranked for one search. */
    public const int CANDIDATES = 200;

    public function __construct(
        private Connection $connection,
        private Journal $journal,
    ) {}

    /**
     * Replaces the rows of these items of a source with the entries.
     *
     * @param list<string>                         $items
     * @param iterable<PublishedEntry|SearchEntry> $entries
     */
    public function replace(string $source, array $items, iterable $entries, string $state = self::DRAFT): void
    {
        if ([] === $items) {
            return;
        }

        $this->journal->trusted(function () use ($source, $items, $entries, $state): void {
            $this->connection->transactional(function () use ($source, $items, $entries, $state): void {
                foreach (array_chunk(array_values(array_unique($items)), 500) as $chunk) {
                    $this->connection->executeStatement(
                        'DELETE FROM ' . self::TABLE . ' WHERE source = ? AND state = ? AND item IN (?)',
                        [$source, $state, $chunk],
                        [2 => ArrayParameterType::STRING],
                    );
                }

                $now = new DateTimeImmutable()->format('Y-m-d H:i:s');

                foreach ($entries as $entry) {
                    $published = $entry instanceof PublishedEntry;
                    $this->connection->insert(self::TABLE, [
                        'source' => $source,
                        'item' => $entry->item,
                        'language' => $entry->language,
                        'state' => $state,
                        'title' => mb_substr($entry->title, 0, 255),
                        'body' => $entry->text,
                        'terms' => SearchTerms::column($entry->title . "\n" . $entry->text, $entry->language),
                        'permission' => $published ? '' : $entry->permission,
                        'scope_kind' => $published ? null : $entry->scopeKind,
                        'scope_id' => $published ? null : $entry->scopeId,
                        'label' => !$published && $entry->label ? 1 : 0,
                        'target' => $published ? (string) $entry->target : null,
                        'updated_at' => $now,
                    ]);
                }
            });
        });
    }

    /**
     * Removes all rows of a source, or all rows.
     */
    public function clear(?string $source = null, string $state = self::DRAFT): void
    {
        $this->journal->trusted(fn(): int|string => null === $source
            ? $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE state = ?', [$state])
            : $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE source = ? AND state = ?', [$source, $state]));
    }

    public function isEmpty(string $state = self::DRAFT): bool
    {
        return false === $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE state = ?', [$state]);
    }

    /**
     * Rows that hold every word, each in one of its spellings, newest first.
     *
     * @param list<list<string>> $words   Spellings per word
     * @param list<string>|null  $sources null for all
     * @param string|null        $language Only entries in this language, e.g. on the website
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(array $words, ?array $sources = null, string $state = self::DRAFT, ?string $language = null): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('state = :state')
            ->setParameter('state', $state)
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(self::CANDIDATES);

        foreach ($words as $index => $spellings) {
            $any = [];

            foreach ($spellings as $number => $spelling) {
                $parameter = 'w' . $index . '_' . $number;
                $any[] = 'terms LIKE :' . $parameter;
                $query->setParameter($parameter, '%' . $spelling . '%');
            }

            $query->andWhere('(' . implode(' OR ', $any) . ')');
        }

        if (null !== $sources) {
            $query->andWhere('source IN (:sources)')->setParameter('sources', $sources, ArrayParameterType::STRING);
        }

        if (null !== $language) {
            $query->andWhere('language = :language')->setParameter('language', $language);
        }

        return $query->executeQuery()->fetchAllAssociative();
    }
}
