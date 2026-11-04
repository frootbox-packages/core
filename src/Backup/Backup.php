<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use DateTimeImmutable;

/**
 * A backup of the database in storage/backups/.
 */
final readonly class Backup
{
    public function __construct(
        public string $name,
        public BackupReason $reason,
        public DateTimeImmutable $createdAt,
        public int $size,
    ) {}
}
