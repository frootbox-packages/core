<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Database\EntityNotFoundException;

/**
 * @implements Action<void>
 */
#[AsAction('system.job-retry', 'Puts a job back in the queue to run now, with all its attempts', undoable: false)]
final readonly class RetryJob implements Action
{
    public function __construct(public string $id) {}

    public function handle(Jobs $jobs): void
    {
        if (null === $jobs->find($this->id)) {
            throw EntityNotFoundException::for(Jobs::TABLE, $this->id);
        }

        $jobs->restart($this->id, new DateTimeImmutable());
    }
}
