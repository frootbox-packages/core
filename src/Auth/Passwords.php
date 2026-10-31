<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * Hashes and checks passwords: Argon2id where PHP has it, bcrypt
 * otherwise. Old hashes are upgraded on the next sign-in.
 */
final class Passwords
{
    private static ?string $dummy = null;

    public function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    /**
     * Without a hash it still spends the time of a check, so a wrong email
     * cannot be told apart from a wrong password by the response time.
     */
    public function verify(string $password, ?string $hash): bool
    {
        if (null === $hash) {
            password_verify($password, self::$dummy ??= password_hash('frootbox', self::algorithm()));

            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    private static function algorithm(): string
    {
        return \in_array(PASSWORD_ARGON2ID, password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
