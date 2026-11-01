<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * Answers in a thread; a guest's reply opens a done thread again
 * (decision 0156).
 *
 * @implements Action<CommentThread>
 */
#[AsAction('comment.reply', 'Replies in the thread of a comment; guests with a preview link reply in the threads of their link', preview: true)]
final readonly class ReplyToComment implements Action
{
    public function __construct(
        #[Description('The thread or one of its comments')]
        #[Pattern(Ulid::PATTERN)]
        public string $commentId,
        #[Description('The text, plain text without markup')]
        #[Length(min: 1, max: CommentEditor::MAX_BODY)]
        public string $body,
        #[Description('The name of a guest with a preview link; users write under their own name')]
        #[Length(min: 1, max: CommentEditor::MAX_NAME)]
        public ?string $authorName = null,
    ) {}

    public function handle(CommentEditor $editor): CommentThread
    {
        return $editor->reply('comment.reply', $this->commentId, $this->body, $this->authorName);
    }
}
