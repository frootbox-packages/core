<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

/**
 * What system.migrate did (decision 0277).
 */
final readonly class MigrationRun
{
    /**
     * @param list<string> $versions the migrations that ran, in order
     * @param string|null  $backup   the name of the backup made before, in storage/backups/
     */
    public function __construct(
        public array $versions,
        public ?string $backup,
    ) {}
}
