<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

/**
 * A migration that has not run yet (decision 0277).
 */
final readonly class PendingMigration
{
    /**
     * @param string      $version     the class of the migration, e.g. "Frootbox\\News\\Migrations\\Version20261101120000"
     * @param string|null $package     the extension it belongs to, e.g. "frootbox/news"
     * @param string      $description what it changes, if the migration says so
     */
    public function __construct(
        public string $version,
        public ?string $package,
        public string $description,
    ) {}
}
