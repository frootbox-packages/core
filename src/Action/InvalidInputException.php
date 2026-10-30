<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * The input does not match the schema of the action or query.
 */
final class InvalidInputException extends ActionException
{
    /**
     * @param array<string, string> $errors Message by JSON pointer, e.g. "/title"
     */
    public function __construct(public readonly string $name, public readonly array $errors)
    {
        $lines = [];

        foreach ($errors as $pointer => $message) {
            $lines[] = ('' === $pointer ? '/' : $pointer) . ': ' . $message;
        }

        parent::__construct(\sprintf('Invalid input for "%s": %s', $name, implode('; ', $lines)));
    }
}
