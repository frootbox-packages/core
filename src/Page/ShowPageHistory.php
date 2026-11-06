<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Change;
use Frootbox\Core\Action\ChangeKind;
use Frootbox\Core\Action\History;
use Frootbox\Core\Action\HistoryEntry;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\RowDiff;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;
use Frootbox\Database\Ulid;
use Frootbox\Database\Values;

/**
 * The history of a page (decision 0174): who changed what and when, also
 * publishing, discarding and the scheduler, with each change resolved to
 * language, block and field with its old and new value. A year long,
 * after the changes can no longer be undone too.
 *
 * @implements Query<PageHistory>
 */
#[AsQuery('page.history', 'Returns who changed a page when, through which channel and on which device, newest first, with each change by language, block and field and its old and new value. Filters by block, path and language; before pages through older entries', permission: 'page.show')]
final readonly class ShowPageHistory implements Query, Scoped
{
    /** Columns that say nothing to a person. */
    private const array IGNORED = ['id', 'type', 'entity_id', 'page_id', 'language', 'version', 'author_id', 'channel', 'action_entry_id', 'published_at', 'created_at', 'updated_at'];

    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Only changes in or of this block, by its id')]
        #[Length(max: 64)]
        public ?string $block = null,
        #[Description('Only changes at or below this path, e.g. "fields/title"')]
        #[Length(max: 500)]
        public ?string $path = null,
        #[Description('Only changes of this language and of the whole page')]
        #[Length(max: 20)]
        public ?string $language = null,
        #[Range(min: 1, max: History::MAX)]
        public int $limit = 50,
        #[Description('Only entries older than this one, the next of the previous page')]
        public ?int $before = null,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(History $history, Connection $connection): PageHistory
    {
        $translations = [];

        foreach ($connection->fetchAllAssociative('SELECT id, language FROM ' . PageTranslations::TABLE . ' WHERE page_id = ?', [$this->id]) as $row) {
            $translations[self::key($row['id'] ?? null)] = \is_string($row['language']) ? $row['language'] : null;
        }

        $revisions = [];

        foreach ($connection->fetchAllAssociative('SELECT id, status FROM ' . Revisions::TABLE . ' WHERE type = ? AND entity_id = ?', [PageReader::TYPE, $this->id]) as $row) {
            $revisions[self::key($row['id'] ?? null)] = \is_string($row['status']) ? $row['status'] : RevisionStatus::Draft->value;
        }

        $page = $history->of(
            [Pages::TABLE => [$this->id], PageTranslations::TABLE => array_keys($translations), Revisions::TABLE => array_keys($revisions)],
            [PageTranslations::TABLE => $this->id, Revisions::TABLE => $this->id],
            $this->ofThisPage(...),
            $this->limit,
            $this->before,
        );

        $entries = [];

        foreach ($page->entries as $entry) {
            $filtered = array_values(array_filter($this->changes($entry, $translations, $revisions, $connection), $this->wanted(...)));

            if ([] === $filtered) {
                continue;
            }

            $log = $entry->entry;
            $entries[] = new PageHistoryEntry(
                $log->id,
                $log->action,
                $log->status->value,
                $log->actorType,
                $log->actorId,
                $log->actorName,
                $log->channel,
                $log->device,
                $log->createdAt,
                $log->undoable,
                null !== $log->undoneBy,
                $log->input ?? [],
                $filtered,
            );
        }

        return new PageHistory($this->typed($entries, $connection), $page->next);
    }

    /**
     * Shortened changes no longer know the type of their block; the
     * revisions of the page still do, as long as the block is in one.
     *
     * @param list<PageHistoryEntry> $entries
     *
     * @return list<PageHistoryEntry>
     */
    private function typed(array $entries, Connection $connection): array
    {
        $types = null;

        foreach ($entries as $index => $entry) {
            foreach ($entry->changes as $key => $change) {
                if (null === $change->block || null !== $change->blockType) {
                    continue;
                }

                if (null === $types) {
                    $types = [];

                    foreach ($connection->fetchFirstColumn('SELECT data FROM ' . Revisions::TABLE . ' WHERE type = ? AND entity_id = ?', [PageReader::TYPE, $this->id]) as $data) {
                        self::collect(\is_string($data) ? json_decode($data, true) : null, $types);
                    }
                }

                $changes = $entries[$index]->changes;
                $changes[$key] = new PageChange($change->kind, $change->state, $change->language, $change->block, $types[$change->block] ?? null, $change->path, $change->old, $change->new);
                $entries[$index] = new PageHistoryEntry($entry->id, $entry->action, $entry->status, $entry->actorType, $entry->actorId, $entry->actorName, $entry->channel, $entry->device, $entry->createdAt, $entry->undoable, $entry->undone, $entry->input, array_values($changes));
            }
        }

        return $entries;
    }

    /**
     * @param array<string, string> $types
     */
    private static function collect(mixed $value, array &$types): void
    {
        if (!\is_array($value)) {
            return;
        }

        if (\is_string($value['id'] ?? null) && \is_string($value['block'] ?? null)) {
            $types[$value['id']] = $value['block'];
        }

        foreach ($value as $item) {
            self::collect($item, $types);
        }
    }

    /**
     * @param array<string, ?string> $translations language by row id
     * @param array<string, string>  $revisions    status by row id
     *
     * @return list<PageChange>
     */
    private function changes(HistoryEntry $entry, array $translations, array $revisions, Connection $connection): array
    {
        $changes = [];

        foreach ($entry->changes as $change) {
            $row = $change->oldValues ?? $change->newValues ?? [];
            $state = match ($change->target) {
                Revisions::TABLE => \is_string($row['status'] ?? null) && !$change->shortened ? $row['status'] : $revisions[(string) $change->rowId] ?? RevisionStatus::Draft->value,
                default => RevisionStatus::Published->value,
            };
            $language = PageTranslations::TABLE === $change->target
                ? (\is_string($row['language'] ?? null) ? $row['language'] : $translations[(string) $change->rowId] ?? null)
                : null;

            $base = $this->base($change, $entry, $connection);

            if (ChangeKind::Update !== $change->kind && null === $base) {
                $changes[] = new PageChange($change->kind->value, $state, $language, null, null, '');

                continue;
            }

            $documents = null === $base ? [$change->oldValues ?? [], $change->newValues ?? []] : [$base, ['data' => $change->newValues['data'] ?? null]];
            [$old, $new] = $change->shortened ? [$change->oldValues ?? [], $change->newValues ?? []] : RowDiff::of(...$documents);

            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $column) {
                if (\in_array($column, self::IGNORED, true)) {
                    continue;
                }

                $was = $old[$column] ?? null;
                $is = $new[$column] ?? null;

                if (\is_array($was) || \is_array($is)) {
                    $wasPaths = \is_array($was) ? $was : [];
                    $isPaths = \is_array($is) ? $is : [];

                    foreach (array_unique([...array_keys($wasPaths), ...array_keys($isPaths)]) as $path) {
                        $changes[] = $this->resolve($state, $language, (string) $path, $wasPaths[$path] ?? null, $isPaths[$path] ?? null, $documents[0][$column] ?? null, $documents[1][$column] ?? null, $change->shortened);
                    }

                    continue;
                }

                $changes[] = new PageChange(ChangeKind::Update->value, $state, $language, null, null, self::camel($column), $was, $is);
            }
        }

        return $changes;
    }

    /**
     * A path in data of a revision or content of a translation, by
     * language, the innermost block and the rest.
     */
    private function resolve(string $state, ?string $language, string $path, mixed $old, mixed $new, mixed $before, mixed $after, bool $shortened): PageChange
    {
        $segments = explode('/', $path);

        if ('translations' === $segments[0] && isset($segments[1])) {
            $language = str_replace(['~1', '~0'], ['/', '~'], $segments[1]);
            $segments = \array_slice($segments, 2);
        }

        if ('content' === ($segments[0] ?? null)) {
            $segments = \array_slice($segments, 1);
        }

        $block = null;
        $blockAt = null;

        foreach ($segments as $index => $segment) {
            if (str_starts_with($segment, '#') && RowDiff::ORDER !== $segment) {
                $block = str_replace(['~1', '~0'], ['/', '~'], substr($segment, 1));
                $blockAt = $index;
            }
        }

        $type = null;

        if (null !== $blockAt) {
            $prefix = implode('/', \array_slice(explode('/', $path), 0, \count(explode('/', $path)) - \count($segments) + $blockAt + 1));

            if (!$shortened) {
                $found = RowDiff::at($after, $prefix) ?? RowDiff::at($before, $prefix);
                $type = \is_array($found) && \is_string($found['block'] ?? null) ? $found['block'] : null;
            } elseif (\is_array($new) && \is_string($new['block'] ?? null)) {
                $type = $new['block'];
            } elseif (\is_array($old) && \is_string($old['block'] ?? null)) {
                $type = $old['block'];
            }

            $segments = \array_slice($segments, $blockAt + 1);
        }

        return new PageChange(ChangeKind::Update->value, $state, $language, $block, $type, implode('/', $segments), $old, $new);
    }

    /**
     * A new draft starts from the published state: what was published
     * then, as a row to compare with. Data of a revision stays as it is
     * once it is published.
     *
     * @return array<string, mixed>|null
     */
    private function base(Change $change, HistoryEntry $entry, Connection $connection): ?array
    {
        if (ChangeKind::Insert !== $change->kind || Revisions::TABLE !== $change->target || $change->shortened) {
            return null;
        }

        $data = $connection->fetchOne(
            $connection->getDatabasePlatform()->modifyLimitQuery('SELECT data FROM ' . Revisions::TABLE . ' WHERE type = ? AND entity_id = ? AND published_at IS NOT NULL AND published_at <= ? AND id <> ? ORDER BY published_at DESC, id DESC', 1),
            [PageReader::TYPE, $this->id, $entry->entry->createdAt->format(Values::DATE_FORMAT), (int) $change->rowId],
        );

        return \is_string($data) ? ['data' => $data] : null;
    }

    /**
     * A deleted row found by the id of the page anywhere in it.
     */
    private function ofThisPage(Change $change): bool
    {
        $row = $change->oldValues ?? [];

        return match ($change->target) {
            PageTranslations::TABLE => ($row['page_id'] ?? null) === $this->id,
            Revisions::TABLE => ($row['entity_id'] ?? null) === $this->id && PageReader::TYPE === ($row['type'] ?? null),
            default => true,
        };
    }

    private function wanted(PageChange $change): bool
    {
        if (null !== $this->language && null !== $change->language && $change->language !== $this->language) {
            return false;
        }

        if (null !== $this->block && $change->block !== $this->block) {
            return false;
        }

        return null === $this->path || $change->path === $this->path || str_starts_with($change->path, rtrim($this->path, '/') . '/');
    }

    private static function camel(string $column): string
    {
        return lcfirst(str_replace('_', '', ucwords($column, '_')));
    }

    private static function key(mixed $id): string
    {
        return \is_int($id) || \is_string($id) ? (string) $id : '';
    }
}
