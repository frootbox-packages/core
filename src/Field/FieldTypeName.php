<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

use Frootbox\Core\Action\ActionName;

/**
 * Names of field types: "headline" in the core, "acme/shop/price" in an
 * extension.
 */
final class FieldTypeName
{
    public static function isValid(string $name): bool
    {
        return 1 === preg_match('#^(?:' . ActionName::PACKAGE . '/)?' . ActionName::SEGMENT . '$#', $name);
    }
}
