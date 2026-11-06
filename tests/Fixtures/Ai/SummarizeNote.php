<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Ai;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Ai\Assistant;
use Frootbox\Core\Ai\Message;
use Frootbox\Core\Ai\Request;

/**
 * A feature of the AI that fails after the answer, e.g. on saving it.
 *
 * @implements Action<string>
 */
#[AsAction('acme/notes/note.summarize', 'Summarizes a text with the AI', permission: 'ai.use')]
final readonly class SummarizeNote implements Action
{
    public function __construct(
        public string $text,
        public bool $fail = false,
    ) {}

    public function handle(Assistant $assistant): string
    {
        $answer = $assistant->ask(new Request('You summarize notes.', [Message::user($this->text)]), 'note.summarize')->text();

        if ($this->fail) {
            throw new InvalidInputException('acme/notes/note.summarize', ['/text' => 'cannot be saved.']);
        }

        return $answer;
    }
}
