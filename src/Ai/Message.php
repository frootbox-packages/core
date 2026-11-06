<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * A message of the conversation, from the user or the model.
 */
final readonly class Message
{
    public const string USER = 'user';

    public const string ASSISTANT = 'assistant';

    /**
     * @param self::USER|self::ASSISTANT $role
     * @param list<Part>                 $parts
     */
    public function __construct(
        public string $role,
        public array $parts,
    ) {}

    public static function user(string|Part ...$parts): self
    {
        return new self(self::USER, array_values(array_map(static fn(string|Part $part): Part => \is_string($part) ? new Text($part) : $part, $parts)));
    }

    public static function assistant(string|Part ...$parts): self
    {
        return new self(self::ASSISTANT, array_values(array_map(static fn(string|Part $part): Part => \is_string($part) ? new Text($part) : $part, $parts)));
    }
}
