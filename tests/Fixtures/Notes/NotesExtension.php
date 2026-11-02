<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;

final class NotesExtension implements Extension
{
    public function register(Registry $registry): void
    {
        foreach ([CreateNote::class, RenameNote::class, DeleteNote::class, CreateNotes::class, CreateNoteWithSql::class, MailNote::class, ShareNote::class, UnshareNote::class, FailingNote::class, Subscribe::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListNotes::class);
        $registry->query(WriteInQuery::class);
        $registry->scope('note', NoteScopes::class);
        $registry->migrations('Frootbox\Core\Tests\Fixtures\Notes\Migrations', __DIR__ . '/Migrations');
    }
}
