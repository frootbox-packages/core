<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Notes;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Action\Schema\Range;

/**
 * @implements Action<Note>
 */
#[AsAction('note.create', 'Creates a note')]
final readonly class CreateNote implements Action
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[Length(min: 1, max: 100)]
        #[Description('Short title')]
        public string $title,
        public ?string $body = null,
        #[Range(min: 0, max: 10)]
        public int $priority = 0,
        public NoteStatus $status = NoteStatus::Draft,
        #[ListOf('string')]
        public array $tags = [],
        public ?\DateTimeImmutable $publishedAt = null,
    ) {}

    public function handle(Notes $notes): Note
    {
        return $notes->insert([
            'title' => $this->title,
            'body' => $this->body,
            'priority' => $this->priority,
            'status' => $this->status,
            'tags' => $this->tags,
            'publishedAt' => $this->publishedAt,
        ]);
    }
}
