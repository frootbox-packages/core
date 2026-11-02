<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database\WriteGuard;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Frootbox\Core\Action\Journal;

/**
 * A prepared write statement. It is reported when it runs.
 *
 * @internal
 */
final class GuardedStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly Journal $journal)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $this->journal->noticeWrite();

        return parent::execute();
    }
}
