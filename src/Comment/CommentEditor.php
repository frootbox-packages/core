<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\Auth\TooManyAttemptsException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Support\Message;
use Frootbox\Core\User\Users;
use Frootbox\Database\EntityNotFoundException;

/**
 * Writes and reads comments for the actions comment.* (decision 0156).
 * Guests with a preview link write under the name they give, see only
 * the threads of their link and are throttled; users write under their
 * name and see all.
 */
final readonly class CommentEditor
{
    /** Comments and replies a link may write within an hour. */
    public const int LIMIT = 30;

    public const int MAX_BODY = 2000;

    public const int MAX_NAME = 80;

    public const int MAX_EXCERPT = 300;

    private const int WINDOW = 3600;

    public function __construct(
        private Comments $comments,
        private CurrentContext $context,
        private PageReader $pages,
        private Languages $languages,
        private Users $users,
    ) {}

    /**
     * @param array{language?: ?string, path?: ?string, blockId?: ?string, field?: ?string, site?: bool, excerpt?: ?string, viewport?: ?int} $place
     *
     * @throws InvalidInputException
     * @throws TooManyAttemptsException
     */
    public function create(string $action, string $pageId, array $place, string $body, ?string $authorName): CommentThread
    {
        $errors = null === $this->pages->draft($pageId) && null === $this->pages->published($pageId) ? ['/pageId' => new Message('is not a page.')] : [];
        $author = $this->author($action, $body, $authorName, $errors);
        $excerpt = self::line($place['excerpt'] ?? null);

        $comment = $this->comments->insert([
            ...$author,
            'page_id' => $pageId,
            'language' => $place['language'] ?? null,
            'path' => $place['path'] ?? null,
            'block_id' => $place['blockId'] ?? null,
            'field' => $place['field'] ?? null,
            'site' => $place['site'] ?? false,
            'excerpt' => null === $excerpt ? null : mb_substr($excerpt, 0, self::MAX_EXCERPT),
            'viewport' => $place['viewport'] ?? null,
            'status' => CommentStatus::Open,
        ]);

        return $this->thread($comment);
    }

    /**
     * A reply to a thread, or to one of its replies. A reply from a guest
     * opens a done thread again: the customer has more to say.
     *
     * @throws InvalidInputException
     * @throws TooManyAttemptsException
     * @throws EntityNotFoundException
     */
    public function reply(string $action, string $commentId, string $body, ?string $authorName): CommentThread
    {
        $thread = $this->visibleThread($commentId);
        $author = $this->author($action, $body, $authorName);
        $this->comments->insert([...$author, 'thread_id' => $thread->id, 'site' => false, 'status' => CommentStatus::Open]);

        if (null === $author['user_id'] && CommentStatus::Done === $thread->status) {
            $thread = $this->comments->update($thread->id, ['status' => CommentStatus::Open, 'resolved_at' => null, 'resolved_by' => null]);
        }

        return $this->thread($thread);
    }

    /**
     * @throws EntityNotFoundException
     */
    public function resolve(string $threadId, bool $done): CommentThread
    {
        $thread = $this->threadOf($threadId);

        if ($done === (CommentStatus::Done === $thread->status)) {
            return $this->thread($thread);
        }

        return $this->thread($this->comments->update($thread->id, $done
            ? ['status' => CommentStatus::Done, 'resolved_at' => new DateTimeImmutable(), 'resolved_by' => $this->context->get()->actor->id]
            : ['status' => CommentStatus::Open, 'resolved_at' => null, 'resolved_by' => null]));
    }

    /**
     * Deletes a reply, or a thread with all its replies.
     *
     * @throws EntityNotFoundException
     */
    public function delete(string $id): void
    {
        $comment = $this->comments->getById($id);

        if ($comment->isThread()) {
            foreach ($this->comments->replies([$comment->id]) as $reply) {
                $this->comments->delete($reply->id);
            }
        }

        $this->comments->delete($comment->id);
    }

    /**
     * The threads a guest of a link or a user may see.
     *
     * @return list<CommentThread>
     */
    public function list(?string $pageId, ?CommentStatus $status): array
    {
        $threads = $this->comments->threads($pageId, $status, $this->link());
        $replies = [];

        foreach ($this->comments->replies(array_map(static fn(Comment $thread): string => $thread->id, $threads)) as $reply) {
            $replies[$reply->threadId ?? $reply->id][] = $reply;
        }

        return array_map(fn(Comment $thread): CommentThread => $this->thread($thread, $replies[$thread->id] ?? []), $threads);
    }

    public function count(?string $pageId): int
    {
        return $this->comments->openThreads($pageId);
    }

    /**
     * Forgets the preview link of its comments before it is deleted; the
     * comments stay (decision 0156).
     */
    public function detach(string $linkId): void
    {
        foreach ($this->comments->byLink($linkId) as $comment) {
            $this->comments->update($comment->id, ['preview_link_id' => null]);
        }
    }

    /**
     * The link of a guest, null for users and the system, who see all.
     */
    private function link(): ?string
    {
        $context = $this->context->get();

        return ActorType::Guest === $context->actor->type ? $context->previewLinkId ?? throw new AccessDeniedException(new Message('Open the preview link again to comment.'), true) : null;
    }

    /**
     * The thread of a comment, for a guest only one of their link.
     *
     * @throws EntityNotFoundException
     */
    private function visibleThread(string $commentId): Comment
    {
        $thread = $this->threadOf($commentId);
        $link = $this->link();

        // Not found rather than denied: a guest learns nothing about other threads.
        if (null !== $link && $thread->previewLinkId !== $link) {
            throw EntityNotFoundException::for(Comments::TABLE, $commentId);
        }

        return $thread;
    }

    /**
     * @throws EntityNotFoundException
     */
    private function threadOf(string $commentId): Comment
    {
        $comment = $this->comments->getById($commentId);

        return null === $comment->threadId ? $comment : $this->comments->getById($comment->threadId);
    }

    /**
     * Who writes: a user under their name, a guest under the name they
     * gave and with their link, throttled.
     *
     * @param array<string, Message> $errors found before
     *
     * @return array{body: string, author_name: string, user_id: ?int, preview_link_id: ?string}
     *
     * @throws InvalidInputException
     * @throws TooManyAttemptsException
     */
    private function author(string $action, string $body, ?string $authorName, array $errors = []): array
    {
        $context = $this->context->get();
        $link = $this->link();
        $body = self::text($body);

        if ('' === $body) {
            $errors['/body'] = new Message('must not be empty.');
        }

        $name = match ($context->actor->type) {
            ActorType::User => $this->users->fetchById((int) $context->actor->id)->name ?? $context->actor->name,
            ActorType::Guest => self::line($authorName),
            ActorType::System => self::line($authorName) ?? $context->actor->name,
        };

        if (null === $name || '' === $name) {
            $errors['/authorName'] = new Message('must not be empty.');
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        if (null !== $link) {
            $this->throttle($link);
        }

        return [
            'body' => $body,
            'author_name' => mb_substr((string) $name, 0, self::MAX_NAME),
            'user_id' => ActorType::User === $context->actor->type ? $context->actor->id : null,
            'preview_link_id' => $link,
        ];
    }

    /**
     * @throws TooManyAttemptsException
     */
    private function throttle(string $linkId, DateTimeImmutable $now = new DateTimeImmutable()): void
    {
        $times = $this->comments->writtenWith($linkId, $now->modify(\sprintf('-%d seconds', self::WINDOW)));

        if (\count($times) < self::LIMIT) {
            return;
        }

        $wait = max(1, $times[\count($times) - self::LIMIT]->getTimestamp() + self::WINDOW - $now->getTimestamp());
        $minutes = max(1, (int) ceil($wait / 60));

        throw new TooManyAttemptsException($wait, 1 === $minutes
            ? new Message('Too many comments. Try again in a minute.')
            : new Message('Too many comments. Try again in {minutes} minutes.', ['minutes' => $minutes]));
    }

    /**
     * @param list<Comment>|null $replies null to load them
     */
    private function thread(Comment $thread, ?array $replies = null): CommentThread
    {
        $replies ??= $this->comments->replies([$thread->id]);
        $page = null === $thread->pageId ? null : $this->pages->draft($thread->pageId) ?? $this->pages->published($thread->pageId);
        $language = $thread->language ?? $this->languages->default()->code;
        $title = null === $page ? null : ($page->translations[$language] ?? array_values($page->translations)[0] ?? null)?->title;
        $resolver = null === $thread->resolvedBy ? null : $this->users->fetchById($thread->resolvedBy);

        return new CommentThread(
            $thread->id,
            $thread->status,
            $thread->pageId,
            $title,
            $thread->language,
            $thread->path,
            $thread->blockId,
            $thread->field,
            $thread->site,
            $thread->excerpt,
            $thread->viewport,
            self::entry($thread),
            array_map(self::entry(...), $replies),
            $thread->resolvedAt,
            $resolver?->name,
        );
    }

    private static function entry(Comment $comment): CommentEntry
    {
        return new CommentEntry($comment->id, $comment->body, $comment->authorName, null === $comment->userId, $comment->createdAt);
    }

    /**
     * Plain text: line breaks and tabs stay, other control characters go.
     */
    private static function text(string $text): string
    {
        return trim((string) preg_replace('/[^\P{Cc}\n\t]|\r/u', '', $text));
    }

    private static function line(?string $text): ?string
    {
        $line = null === $text ? null : trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cc}/u', ' ', $text)));

        return '' === $line ? null : $line;
    }
}
