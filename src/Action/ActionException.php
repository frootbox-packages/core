<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Support\Message;
use RuntimeException;
use Throwable;

/**
 * Base of the errors a caller of an action or query can react to. The
 * message can be a template with values, so the backend can translate it
 * (decision 0115).
 */
abstract class ActionException extends RuntimeException
{
    public readonly Message $template;

    public function __construct(string|Message $message = '', int $code = 0, ?Throwable $previous = null)
    {
        $this->template = Message::of($message);
        parent::__construct((string) $this->template, $code, $previous);
    }
}
