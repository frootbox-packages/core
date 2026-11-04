<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Checked;

use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;

/**
 * The package acme/checked for the tests of extension:check. Its
 * classes are not in the autoloader of the tests, the check loads them.
 */
final class CheckedExtension implements Extension
{
    public function register(Registry $registry): void
    {
        $registry->action(ArchiveItem::class);
        $registry->query(ListItems::class);
    }
}
