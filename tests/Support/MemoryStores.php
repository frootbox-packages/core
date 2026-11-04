<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use Frootbox\Core\Storage\Dsn;
use Frootbox\Core\Storage\FileStore;
use Frootbox\Core\Storage\StoreFactory;
use Frootbox\Core\Storage\StoreRole;

/**
 * Makes MemoryStore for the scheme memory://.
 */
final class MemoryStores implements StoreFactory
{
    public function create(Dsn $dsn, StoreRole $role, string $cache): FileStore
    {
        return new MemoryStore($dsn, $cache);
    }
}
