<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * For the counters in the menu and the editor bar (decision 0156).
 *
 * @implements Query<int>
 */
#[AsQuery('comment.count', 'Returns how many threads of comments are open, on the website or on a page', permission: 'comment.list')]
final readonly class CountComments implements Query
{
    public function __construct(
        #[Description('Only the threads on this page')]
        #[Pattern(Ulid::PATTERN)]
        public ?string $pageId = null,
    ) {}

    public function handle(CommentEditor $editor): int
    {
        return $editor->count($this->pageId);
    }
}
