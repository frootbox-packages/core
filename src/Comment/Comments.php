<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use DateTimeImmutable;
use Frootbox\Database\Repository;

/**
 * @extends Repository<Comment>
 */
final class Comments extends Repository
{
    public const string TABLE = 'comments';

    /**
     * The first comments of the threads, newest first.
     *
     * @param string|null $linkId Only the threads opened with this preview link
     *
     * @return list<Comment>
     */
    public function threads(?string $pageId = null, ?CommentStatus $status = null, ?string $linkId = null): array
    {
        $where = ['threadId' => null];

        if (null !== $pageId) {
            $where['pageId'] = $pageId;
        }

        if (null !== $status) {
            $where['status'] = $status;
        }

        if (null !== $linkId) {
            $where['previewLinkId'] = $linkId;
        }

        return $this->fetch(['where' => $where, 'order' => ['created_at desc', 'id desc']])->all();
    }

    /**
     * The replies to these threads, oldest first.
     *
     * @param list<string> $threadIds
     *
     * @return list<Comment>
     */
    public function replies(array $threadIds): array
    {
        return [] === $threadIds ? [] : $this->fetch(['where' => ['threadId' => $threadIds], 'order' => ['created_at asc', 'id asc']])->all();
    }

    /**
     * How many open threads there are, e.g. for the counter in the menu.
     */
    public function openThreads(?string $pageId = null): int
    {
        $where = ['threadId' => null, 'status' => CommentStatus::Open];

        return $this->count(null === $pageId ? $where : [...$where, 'pageId' => $pageId]);
    }

    /**
     * The times guests wrote with a link since then, oldest first.
     *
     * @return list<DateTimeImmutable>
     */
    public function writtenWith(string $linkId, DateTimeImmutable $since): array
    {
        $comments = $this->fetch(['where' => ['previewLinkId' => $linkId, 'userId' => null, 'createdAt >=' => $since], 'order' => ['created_at asc']])->all();

        return array_values(array_filter(array_map(static fn(Comment $comment): ?DateTimeImmutable => $comment->createdAt, $comments)));
    }

    /**
     * @return list<Comment>
     */
    public function byLink(string $linkId): array
    {
        return $this->fetch(['where' => ['previewLinkId' => $linkId]])->all();
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Comment::class;
    }
}
