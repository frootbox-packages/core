<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Database\Repository;

/**
 * @extends Repository<Medium>
 */
final class Media extends Repository
{
    public const string TABLE = 'media';

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Medium::class;
    }
}
