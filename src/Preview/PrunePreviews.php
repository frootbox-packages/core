<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Comment\CommentEditor;

/**
 * Deletes preview links 30 days after they expired or were revoked
 * (decision 0154), keeping their comments (decision 0156). Run by
 * schedule:run.
 *
 * @implements Action<int>
 */
#[AsAction('system.prune-previews', 'Deletes preview links 30 days after they expired or were revoked and returns how many')]
final readonly class PrunePreviews implements Action
{
    public function handle(PreviewLinks $links, CommentEditor $comments): int
    {
        $deleted = 0;

        foreach ($links->endedBefore(self::before()) as $link) {
            // The comments stay, without the link (decision 0156).
            $comments->detach($link->id);
            $links->delete($link->id);
            ++$deleted;
        }

        return $deleted;
    }

    /**
     * Links that ended before then are deleted.
     */
    public static function before(): DateTimeImmutable
    {
        return new DateTimeImmutable()->modify(\sprintf('-%d days', PreviewEditor::KEEP_DAYS));
    }
}
