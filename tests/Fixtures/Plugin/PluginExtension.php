<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;

/**
 * A plugin as a third party would write it (decision 0219).
 */
final class PluginExtension implements Extension
{
    public function register(Registry $registry): void {}
}
