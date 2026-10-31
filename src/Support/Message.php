<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Support;

use Stringable;

/**
 * A message as an English template with {placeholders} and their values
 * (decision 0115). The REST API shows the finished text, the backend
 * translates the template first:
 *
 *     new Message('must be at least {min} characters long.', ['min' => 10])
 */
final readonly class Message implements Stringable
{
    /**
     * @param array<string, bool|float|int|string|null> $parameters
     */
    public function __construct(
        public string $text,
        public array $parameters = [],
    ) {}

    public static function of(string|self $message): self
    {
        return $message instanceof self ? $message : new self($message);
    }

    public function __toString(): string
    {
        $replace = [];

        foreach ($this->parameters as $name => $value) {
            $replace['{' . $name . '}'] = match (true) {
                true === $value => 'true',
                false === $value => 'false',
                default => (string) $value,
            };
        }

        return strtr($this->text, $replace);
    }
}
