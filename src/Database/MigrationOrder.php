<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * Runs the migrations of all packages in the order of their time in the
 * class name (Version20261213090000), not of their namespace: a package
 * whose name comes before "Core", such as Frootbox\Collections, would
 * otherwise run before the tables of the core exist (decision 0247).
 */
final class MigrationOrder implements Comparator
{
    public function compare(Version $a, Version $b): int
    {
        return [self::name($a), (string) $a] <=> [self::name($b), (string) $b];
    }

    private static function name(Version $version): string
    {
        $class = (string) $version;
        $slash = strrpos($class, '\\');

        return false === $slash ? $class : substr($class, $slash + 1);
    }
}
