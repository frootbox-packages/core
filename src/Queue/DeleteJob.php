<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Database\EntityNotFoundException;

/**
 * @implements Action<void>
 */
#[AsAction('system.job-delete', 'Removes a job from the queue without running it', undoable: false)]
final readonly class DeleteJob implements Action
{
    public function __construct(public string $id) {}

    public function handle(Jobs $jobs): void
    {
        if (!$jobs->delete($this->id)) {
            throw EntityNotFoundException::for(Jobs::TABLE, $this->id);
        }
    }
}
