<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Database\Repository;

/**
 * @extends Repository<User>
 */
final class Users extends Repository
{
    public const string TABLE = 'users';

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function findByEmail(string $email): ?User
    {
        return $this->fetchOne(['where' => ['email' => self::normalizeEmail($email)]]);
    }

    /**
     * The stored password hash, null if the user has none (yet).
     */
    public function passwordHash(int $id): ?string
    {
        $hash = $this->connection->fetchOne('SELECT password FROM ' . self::TABLE . ' WHERE id = ?', [$id]);

        return \is_string($hash) && '' !== $hash ? $hash : null;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return User::class;
    }
}
