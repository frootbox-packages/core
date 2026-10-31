<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * Names of actions and queries.
 *
 * The core uses plain names such as "user.create". An extension puts its
 * Composer package name in front, e.g. "acme/shop/order.create", so names
 * of different extensions never collide.
 */
final class ActionName
{
    /** A Composer package name, e.g. "acme/shop". */
    public const string PACKAGE = '[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*';

    /** One part of a name, e.g. "order" or "set-password". */
    public const string SEGMENT = '[a-z][a-z0-9]*(?:-[a-z0-9]+)*';

    /** The name within the package: at least two parts, e.g. "order.create". */
    public const string LOCAL = self::SEGMENT . '(?:\.' . self::SEGMENT . ')+';

    public static function isValid(string $name): bool
    {
        return 1 === preg_match('#^(?:' . self::PACKAGE . '/)?' . self::LOCAL . '$#', $name);
    }

    /**
     * The package of a name, or null for the core.
     */
    public static function package(string $name): ?string
    {
        $slash = strrpos($name, '/');

        return false === $slash ? null : substr($name, 0, $slash);
    }

    /**
     * The name without its package, e.g. "order.create".
     */
    public static function local(string $name): string
    {
        $slash = strrpos($name, '/');

        return false === $slash ? $name : substr($name, $slash + 1);
    }
}
