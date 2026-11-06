<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * Why an answer ended.
 */
enum StopReason: string
{
    /** The model is done. */
    case Done = 'done';
    /** It reached the highest number of output tokens of the request. */
    case Limit = 'limit';
    /** It wants to call tools. */
    case Tools = 'tools';
    /** It declined the request. */
    case Refused = 'refused';
}
