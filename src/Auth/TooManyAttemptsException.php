<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Support\Message;

/**
 * Too many failed sign-ins: wait before the next try (decision 0115).
 * Answers 429 with Retry-After.
 */
final class TooManyAttemptsException extends ActionException
{
    /**
     * @param int $retryAfter seconds to wait
     */
    public function __construct(public readonly int $retryAfter)
    {
        $minutes = max(1, (int) ceil($retryAfter / 60));

        parent::__construct(1 === $minutes
            ? new Message('Too many failed attempts. Try again in a minute.')
            : new Message('Too many failed attempts. Try again in {minutes} minutes.', ['minutes' => $minutes]));
    }
}
