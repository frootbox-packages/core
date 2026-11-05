<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Database\Repository;

/**
 * At most one row: the website has one identity at the hub (decision 0284).
 *
 * @extends Repository<HubConnection>
 */
final class HubConnections extends Repository
{
    public const string TABLE = 'hub_connections';

    public function current(): ?HubConnection
    {
        return $this->fetchOne(['order' => ['id' => 'DESC']]);
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return HubConnection::class;
    }
}
