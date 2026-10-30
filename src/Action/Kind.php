<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

enum Kind: string
{
    case Action = 'action';
    case Query = 'query';
}
