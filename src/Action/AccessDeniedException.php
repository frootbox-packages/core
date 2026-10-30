<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Auth\Actor;

final class AccessDeniedException extends ActionException
{
    public static function for(Descriptor $descriptor, Actor $actor): self
    {
        return new self(\sprintf('%s may not run "%s" (permission "%s").', $actor->name, $descriptor->name, $descriptor->permission));
    }

    public static function forPermission(string $permission, Actor $actor): self
    {
        return new self(\sprintf('%s lacks the permission "%s".', $actor->name, $permission));
    }
}
