<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * How thoroughly the model works on a request; more costs more.
 */
enum Effort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
