<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;

/**
 * A search source of an extension, as a third party would write it.
 */
#[AsSearchSource('acme/notes/note', 'Notes')]
final readonly class NoteSource implements SearchSource
{
    public function __construct(private Notes $notes) {}

    public function changed(string $table, array $row): array
    {
        return 'notes' === $table && \is_int($row['id'] ?? null) ? [(string) $row['id']] : [];
    }

    public function items(): iterable
    {
        foreach ($this->notes->fetch(['order' => 'id']) as $note) {
            yield (string) $note->id;
        }
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $id) {
            $note = ctype_digit($id) ? $this->notes->fetchById((int) $id) : null;

            if (null !== $note) {
                yield new SearchEntry($id, $note->title, $note->body ?? '', 'note.list');
            }
        }
    }
}
