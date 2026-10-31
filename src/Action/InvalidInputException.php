<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Support\Message;

/**
 * The input does not match the schema of the action or query.
 */
final class InvalidInputException extends ActionException
{
    /** @var array<string, string> finished text by JSON pointer, e.g. "/title" */
    public readonly array $errors;

    /** @var array<string, Message> template and values by JSON pointer, for translation */
    public readonly array $messages;

    /**
     * @param array<string, string|Message> $errors by JSON pointer, e.g. "/title"
     */
    public function __construct(public readonly string $name, array $errors)
    {
        $this->messages = array_map(Message::of(...), $errors);
        $this->errors = array_map(static fn(Message $message): string => (string) $message, $this->messages);
        $lines = [];

        foreach ($this->errors as $pointer => $message) {
            $lines[] = ('' === $pointer ? '/' : $pointer) . ': ' . $message;
        }

        parent::__construct(\sprintf('Invalid input for "%s": %s', $name, implode('; ', $lines)));
    }
}
