<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

enum ChangeKind: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';

    /** A compensating action that runs when the action is undone. */
    case Action = 'action';
}
