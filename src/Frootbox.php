<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core;

/**
 * Basic facts about the running Frootbox core.
 */
final class Frootbox
{
    public const string VERSION = '0.1.0-dev';

    public const string MINIMUM_PHP_VERSION = '8.5.0';

    /** The SQLite library in pdo_sqlite, the oldest in a supported Linux (RHEL 9). */
    public const string MINIMUM_SQLITE_VERSION = '3.34.0';
}
