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
 * Decision 0156.
 *
 * @implements Action<CommentThread>
 */
#[AsAction('comment.resolve', 'Marks a thread of comments as done')]
final readonly class ResolveComment implements Action
{
    public function __construct(
        #[Description('The thread or one of its comments')]
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(CommentEditor $editor): CommentThread
    {
        return $editor->resolve($this->id, true);
    }
}
