<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Database\EntityNotFoundException;
use Frootbox\Database\Repository;

/**
 * @extends Repository<Role>
 */
final class Roles extends Repository
{
    public const string TABLE = 'roles';

    public function findByName(string $name): ?Role
    {
        return $this->fetchOne(['where' => ['name' => $name]]);
    }

    public function getByName(string $name): Role
    {
        return $this->findByName($name) ?? throw EntityNotFoundException::for(self::TABLE, $name);
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Role::class;
    }
}
