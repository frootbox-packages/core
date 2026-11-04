<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

/**
 * The backups and what the database allows.
 */
final readonly class BackupList
{
    /**
     * @param list<Backup> $backups newest first
     */
    public function __construct(
        public array $backups,
        public bool $available,
        public bool $canRestore,
    ) {}
}
