<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * Writes past the repository with plain DBAL.
 */
#[AsAction('note.create-with-sql', 'Creates a note with plain SQL')]
final readonly class CreateNoteWithSql implements Action
{
    public function __construct(public string $title) {}

    public function handle(Connection $connection): void
    {
        $connection->insert('notes', ['title' => $this->title, 'created_at' => new DateTimeImmutable()->format('Y-m-d H:i:s')]);
    }
}
