<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

enum ActorType: string
{
    case System = 'system';
    case User = 'user';
    case Guest = 'guest';
}
