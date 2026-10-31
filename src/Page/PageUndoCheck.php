<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Change;
use Frootbox\Core\Action\Undo\UndoCheck;
use Frootbox\Core\Action\Undo\UndoConflict;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;

/**
 * Keeps the page tree whole when actions are undone in the wrong order
 * (decision 0102): at most one draft and one published state per page,
 * one start page, every parent there and slugs unique next to each other.
 * Checked for the tree editors see and for the published one.
 */
final readonly class PageUndoCheck implements UndoCheck
{
    public function __construct(
        private PageReader $reader,
        private Revisions $revisions,
        private PageTranslations $translations,
    ) {}

    public function check(array $changes): array
    {
        $ids = self::pageIds($changes);

        if ([] === $ids) {
            return [];
        }

        $conflicts = [];

        foreach ($ids as $id) {
            foreach ([RevisionStatus::Draft, RevisionStatus::Published] as $status) {
                $found = $this->revisions->fetch(['where' => ['type' => PageReader::TYPE, 'entityId' => $id, 'status' => $status]])->all();

                if (\count($found) > 1) {
                    $conflicts[] = new UndoConflict(
                        \sprintf('The page %s would have %d %s revisions.', $id, \count($found), $status->value),
                        [Revisions::TABLE => array_map(static fn(Revision $revision): int => $revision->id, $found)],
                    );
                }
            }
        }

        foreach ([$this->reader->nodes(), $this->reader->nodesAfterPublishing([])] as $nodes) {
            array_push($conflicts, ...$this->tree($ids, $nodes));
        }

        // Both trees often show the same problem.
        $unique = [];

        foreach ($conflicts as $conflict) {
            $unique[$conflict->message] ??= $conflict;
        }

        return array_values($unique);
    }

    /**
     * @param list<string>            $ids
     * @param array<string, PageNode> $nodes
     *
     * @return list<UndoConflict>
     */
    private function tree(array $ids, array $nodes): array
    {
        $conflicts = [];
        $roots = PageReader::children($nodes, null);

        if (\count($roots) > 1) {
            $conflicts[] = new UndoConflict(
                \sprintf('There would be %d start pages: %s.', \count($roots), implode(', ', array_map(static fn(PageNode $node): string => $node->id, $roots))),
                $this->rowsOf(...array_map(static fn(PageNode $node): string => $node->id, $roots)),
            );
        }

        foreach ($ids as $id) {
            $node = $nodes[$id] ?? null;

            if (null === $node) {
                $children = PageReader::children($nodes, $id);

                if ([] !== $children) {
                    $conflicts[] = new UndoConflict(
                        \sprintf('The page %s would be gone, but still has subpages.', $id),
                        $this->rowsOf(...array_map(static fn(PageNode $child): string => $child->id, $children)),
                    );
                }

                continue;
            }

            $parentId = $node->data->parentId;

            if (null === $parentId) {
                continue;
            }

            if (!isset($nodes[$parentId])) {
                $conflicts[] = new UndoConflict(
                    \sprintf('The page %s would have no parent: %s is gone.', $id, $parentId),
                    $this->rowsOf($parentId),
                );

                continue;
            }

            foreach (PageReader::children($nodes, $parentId) as $sibling) {
                if ($sibling->id === $id) {
                    continue;
                }

                foreach ($node->data->translations as $language => $translation) {
                    if (($sibling->data->translations[$language] ?? null)?->slug === $translation->slug) {
                        $conflicts[] = new UndoConflict(
                            \sprintf('The pages %s and %s would have the same slug "%s".', $id, $sibling->id, $translation->slug),
                            $this->rowsOf($sibling->id),
                        );
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * The rows that make up these pages, to find the actions that changed them.
     *
     * @return array<string, list<int|string>>
     */
    private function rowsOf(string ...$ids): array
    {
        $rows = [Pages::TABLE => array_values($ids), Revisions::TABLE => [], PageTranslations::TABLE => []];

        foreach ($ids as $id) {
            foreach ($this->revisions->fetch(['where' => ['type' => PageReader::TYPE, 'entityId' => $id]]) as $revision) {
                $rows[Revisions::TABLE][] = $revision->id;
            }

            foreach ($this->translations->of($id) as $translation) {
                $rows[PageTranslations::TABLE][] = $translation->id;
            }
        }

        return $rows;
    }

    /**
     * The pages the undone changes touched.
     *
     * @param list<Change> $changes
     *
     * @return list<string>
     */
    private static function pageIds(array $changes): array
    {
        $ids = [];

        foreach ($changes as $change) {
            $id = match ($change->target) {
                Pages::TABLE => $change->rowId,
                PageTranslations::TABLE => self::value($change, 'page_id'),
                Revisions::TABLE => PageReader::TYPE === self::value($change, 'type') ? self::value($change, 'entity_id') : null,
                default => null,
            };

            if (null !== $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private static function value(Change $change, string $column): ?string
    {
        $value = $change->newValues[$column] ?? $change->oldValues[$column] ?? null;

        return \is_string($value) ? $value : null;
    }
}
