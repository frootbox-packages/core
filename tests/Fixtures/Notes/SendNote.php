<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Queue\AsJob;
use Frootbox\Core\Queue\Job;

/**
 * A job: records who it ran as, or fails.
 */
#[AsJob('acme/notes/note.send', 'Sends a note', attempts: 3)]
final readonly class SendNote implements Job
{
    public function __construct(
        public int $id,
        public bool $fail = false,
    ) {}

    public function handle(CurrentContext $context, NotesSettings $settings): void
    {
        if ($this->fail) {
            throw new \RuntimeException('The note server is down.');
        }

        Outbox::$messages[] = \sprintf('sent #%d as %s from %s', $this->id, $context->get()->actor->name, $settings->sender);
    }
}
