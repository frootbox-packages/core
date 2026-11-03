<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Database;

use Doctrine\Migrations\Version\Version;
use Frootbox\Core\Database\MigrationOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationOrder::class)]
final class MigrationOrderTest extends TestCase
{
    public function testOrdersByTheTimeInTheNameAcrossPackages(): void
    {
        $versions = [
            new Version('Frootbox\Collections\Migrations\Version20261213090000'),
            new Version('Frootbox\Core\Migrations\Version20261212090000'),
            new Version('Frootbox\Core\Migrations\Version20261030120000'),
            new Version('Acme\Shop\Migrations\Version20261213090000'),
        ];
        $order = new MigrationOrder();

        usort($versions, $order->compare(...));

        self::assertSame([
            'Frootbox\Core\Migrations\Version20261030120000',
            'Frootbox\Core\Migrations\Version20261212090000',
            'Acme\Shop\Migrations\Version20261213090000',
            'Frootbox\Collections\Migrations\Version20261213090000',
        ], array_map(strval(...), $versions), 'The same time by the whole name.');
    }
}
