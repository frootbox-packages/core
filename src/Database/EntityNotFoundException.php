<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use RuntimeException;

final class EntityNotFoundException extends RuntimeException
{
    public static function for(string $table, int|string $id): self
    {
        return new self(\sprintf('There is no row #%s in %s.', $id, $table));
    }
}
