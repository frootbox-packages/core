<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Something else holds what the action needs right now, e.g. another step
 * of a sync or the database (503 with Retry-After, decision 0331). The
 * same call a moment later works; nothing is wrong with the input.
 */
final class BusyException extends ActionException
{
    /** Seconds to wait before the next try. */
    public const int RETRY_AFTER = 3;

    public static function database(): self
    {
        return new self('The website is busy right now. Please try again in a moment.');
    }
}
