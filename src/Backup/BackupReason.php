<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

/**
 * Why a backup was made, part of its file name (decision 0183).
 */
enum BackupReason: string
{
    /** Before the first change of the day. */
    case Daily = 'daily';

    /** Before migrations ran. */
    case Migrate = 'migrate';

    /** By hand; stays until someone deletes it. */
    case Manual = 'manual';

    /** The state just before a backup was restored. */
    case Restore = 'restore';
}
