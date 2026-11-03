<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Database\Repository;

/**
 * @extends Repository<AdminGroup>
 */
final class AdminGroups extends Repository
{
    public const string TABLE = 'category_groups';

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return AdminGroup::class;
    }
}
