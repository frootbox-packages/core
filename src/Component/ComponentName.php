<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\ActionName;

/**
 * Names of blocks and layouts: the package they come from plus a name in
 * PascalCase, e.g. "frootbox/base/Teaser".
 */
final class ComponentName
{
    /** A name within the package, also used for variants, e.g. "ImageLeft". */
    public const string LOCAL = '[A-Z][A-Za-z0-9]*';

    public static function isLocal(string $name): bool
    {
        return 1 === preg_match('/^' . self::LOCAL . '$/', $name);
    }

    public static function isValid(string $name): bool
    {
        return 1 === preg_match('#^' . ActionName::PACKAGE . '/' . self::LOCAL . '$#', $name);
    }

    /**
     * Completes a short name with the package: "Column" in acme/website
     * becomes "acme/website/Column". Full names stay as they are.
     */
    public static function resolve(string $name, string $package): string
    {
        return str_contains($name, '/') ? $name : $package . '/' . $name;
    }

    public static function package(string $name): string
    {
        return substr($name, 0, (int) strrpos($name, '/'));
    }

    public static function local(string $name): string
    {
        return substr($name, (int) strrpos($name, '/') + 1);
    }
}
