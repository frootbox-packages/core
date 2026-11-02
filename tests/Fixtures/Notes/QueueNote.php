<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Queue\Queue;

/**
 * Puts a job in the queue, then fails if asked to.
 *
 * @implements Action<string>
 */
#[AsAction('note.queue', 'Sends a note in the background')]
final readonly class QueueNote implements Action
{
    public function __construct(
        public int $id,
        public bool $failAfterwards = false,
        public bool $failInJob = false,
        public int $delay = 0,
    ) {}

    public function handle(Queue $queue): string
    {
        $job = $queue->push(new SendNote($this->id, $this->failInJob), $this->delay);

        if ($this->failAfterwards) {
            throw new \RuntimeException('Something went wrong after queueing.');
        }

        return $job;
    }
}
