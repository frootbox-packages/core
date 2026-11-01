<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * Comments stay until someone deletes them (decision 0156).
 *
 * @implements Action<void>
 */
#[AsAction('comment.delete', 'Deletes a reply, or a whole thread with its replies')]
final readonly class DeleteComment implements Action
{
    public function __construct(
        #[Description('The reply, or the first comment of the thread')]
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(CommentEditor $editor): void
    {
        $editor->delete($this->id);
    }
}
