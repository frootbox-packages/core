<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core;

use Composer\InstalledVersions;

/**
 * Basic facts about the running Frootbox core.
 */
final class Frootbox
{
    /**
     * The version of this code: the next release with "-dev" on main, the
     * release itself in a tag. All packages share it (decision 0271).
     */
    public const string VERSION = '0.1.0-dev';

    public const string MINIMUM_PHP_VERSION = '8.5.0';

    /** The SQLite library in pdo_sqlite, the oldest in a supported Linux (RHEL 9). */
    public const string MINIMUM_SQLITE_VERSION = '3.34.0';

    /**
     * The installed version of frootbox/core as Composer knows it, for
     * "about" and the backend. A branch (dev-main) says nothing about the
     * code, so it falls back to VERSION.
     */
    public static function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('frootbox/core')) {
            $version = InstalledVersions::getPrettyVersion('frootbox/core');

            if (null !== $version && !str_contains($version, 'dev')) {
                return ltrim($version, 'v');
            }
        }

        return self::VERSION;
    }
}
