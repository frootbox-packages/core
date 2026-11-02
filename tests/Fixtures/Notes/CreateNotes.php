<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\Schema\ListOf;

/**
 * Runs note.create for every title: nested actions.
 *
 * @implements Action<list<int>>
 */
#[AsAction('note.create-many', 'Creates several notes')]
final readonly class CreateNotes implements Action
{
    /**
     * @param list<string> $titles
     */
    public function __construct(
        #[ListOf('string')]
        public array $titles,
    ) {}

    /**
     * @return list<int>
     */
    public function handle(Runner $runner): array
    {
        return array_map(static fn(string $title): int => $runner->run(new CreateNote($title))->id, $this->titles);
    }
}
