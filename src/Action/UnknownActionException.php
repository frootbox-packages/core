<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

final class UnknownActionException extends ActionException
{
    public static function named(string $name): self
    {
        return new self(\sprintf('There is no action or query named "%s".', $name));
    }
}
