<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Database\Repository;

/**
 * @extends Repository<SpaceDefault>
 */
final class SpaceDefaults extends Repository
{
    public const string TABLE = 'space_defaults';

    public function ofType(string $type): ?SpaceDefault
    {
        return $this->fetchOne(['where' => ['type' => $type]]);
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return SpaceDefault::class;
    }
}
