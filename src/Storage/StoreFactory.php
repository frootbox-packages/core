<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

/**
 * Makes a store of one kind from its DSN (decision 0268), announced by an
 * extension as a StorageDriver and loaded from the container.
 */
interface StoreFactory
{
    /**
     * @param string $cache A folder below var/storage/ for copies on disk
     *
     * @throws StorageException If the DSN is not complete
     */
    public function create(Dsn $dsn, StoreRole $role, string $cache): FileStore;
}
