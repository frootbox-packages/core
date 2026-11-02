<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

enum ActorType: string
{
    case System = 'system';
    case User = 'user';
    case Guest = 'guest';
}
