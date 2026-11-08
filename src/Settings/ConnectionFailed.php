<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Support\Message;
use RuntimeException;
use Throwable;

/**
 * A test of the connection failed (decision 0353), with a sentence for
 * the person who set it up, e.g. that the secret does not fit.
 */
final class ConnectionFailed extends RuntimeException
{
    public readonly Message $text;

    public function __construct(string|Message $text, ?Throwable $previous = null)
    {
        $this->text = Message::of($text);

        parent::__construct((string) $this->text, 0, $previous);
    }
}
