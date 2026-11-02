<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Database\Repository;

/**
 * @extends Repository<Page>
 */
final class Pages extends Repository
{
    public const string TABLE = 'pages';

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Page::class;
    }
}
