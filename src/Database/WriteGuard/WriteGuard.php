<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database\WriteGuard;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Frootbox\Core\Action\Journal;

/**
 * Watches every statement sent to the database. A write that did not
 * come through a repository (own SQL, DBAL directly) makes the running
 * action not undoable, instead of undoing it only halfway later.
 */
final readonly class WriteGuard implements Middleware
{
    private const string WRITE = '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|MERGE|UPSERT|CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i';

    public function __construct(private Journal $journal) {}

    public static function isWrite(string $sql): bool
    {
        return 1 === preg_match(self::WRITE, $sql);
    }

    public function wrap(Driver $driver): Driver
    {
        return new GuardedDriver($driver, $this->journal);
    }
}
