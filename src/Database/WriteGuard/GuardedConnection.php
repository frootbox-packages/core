<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database\WriteGuard;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Frootbox\Core\Action\Journal;

/**
 * @internal
 */
final class GuardedConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly Journal $journal)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $statement = parent::prepare($sql);

        return WriteGuard::isWrite($sql) ? new GuardedStatement($statement, $this->journal) : $statement;
    }

    public function query(string $sql): Result
    {
        $this->notice($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->notice($sql);

        return parent::exec($sql);
    }

    private function notice(string $sql): void
    {
        if (WriteGuard::isWrite($sql)) {
            $this->journal->noticeWrite();
        }
    }
}
