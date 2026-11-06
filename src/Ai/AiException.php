<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use Frootbox\Core\Support\Message;
use RuntimeException;
use Throwable;

/**
 * The provider could not answer: no connection, a wrong key, too many
 * requests or a request it refuses. The message is for people, as an
 * English template the backend translates.
 */
final class AiException extends RuntimeException
{
    public readonly Message $text;

    public function __construct(string|Message $message, ?Throwable $previous = null)
    {
        $this->text = Message::of($message);
        parent::__construct((string) $this->text, 0, $previous);
    }
}
