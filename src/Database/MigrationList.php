<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

/**
 * The migrations that have not run yet (decision 0277).
 */
final readonly class MigrationList
{
    /**
     * @param list<PendingMigration> $pending in the order they run
     * @param bool                   $backup  whether a backup can be made before
     */
    public function __construct(
        public array $pending,
        public bool $backup,
    ) {}
}
