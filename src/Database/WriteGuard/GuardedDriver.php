<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database\WriteGuard;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Frootbox\Core\Action\Journal;
use SensitiveParameter;

/**
 * @internal
 */
final class GuardedDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly Journal $journal)
    {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new GuardedConnection(parent::connect($params), $this->journal);
    }
}
