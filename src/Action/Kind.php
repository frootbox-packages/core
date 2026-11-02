<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

enum Kind: string
{
    case Action = 'action';
    case Query = 'query';
}
