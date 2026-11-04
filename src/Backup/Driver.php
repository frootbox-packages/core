<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

/**
 * Makes backups of one kind of database (decision 0183).
 */
interface Driver
{
    /**
     * Whether there is a database to back up at all; false e.g. for
     * SQLite in memory.
     */
    public function available(): bool;

    /**
     * The file extension of the backups, e.g. "sqlite.gz".
     */
    public function extension(): string;

    /**
     * Whether a backup takes long, so the daily one waits for the
     * scheduler instead of running before an action.
     */
    public function slow(): bool;

    /**
     * Writes a backup of the current state to the file.
     */
    public function create(string $file): void;

    public function canRestore(): bool;

    /**
     * The migrations the backup has run, to refuse one newer than the code.
     *
     * @return list<string>
     */
    public function migrations(string $file): array;

    /**
     * Replaces the database with the backup. The caller locks the website.
     */
    public function restore(string $file): void;
}
