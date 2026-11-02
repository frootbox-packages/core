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
 * Guests with a preview link get the threads of their link (decision 0156).
 *
 * @implements Query<list<CommentThread>>
 */
#[AsQuery('comment.list', 'Returns the threads of comments, newest first, with place, status and replies; guests with a preview link get those of their link', preview: true)]
final readonly class ListComments implements Query
{
    public function __construct(
        #[Description('Only the threads on this page')]
        #[Pattern(Ulid::PATTERN)]
        public ?string $pageId = null,
        #[Description('Only open or only done threads')]
        public ?CommentStatus $status = null,
    ) {}

    /**
     * @return list<CommentThread>
     */
    public function handle(CommentEditor $editor): array
    {
        return $editor->list($this->pageId, $this->status);
    }
}
