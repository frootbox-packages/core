<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Action;

use Frootbox\Core\Action\ActionName;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Tests\Fixtures\Notes\ListNotes;
use Frootbox\Core\Tests\Fixtures\Shop\CreateOrder;
use Frootbox\Core\Tests\Fixtures\Shop\ListOrders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionName::class)]
#[CoversClass(Catalog::class)]
#[CoversClass(Registry::class)]
final class ActionNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'core' => ['user.create', true];
        yield 'core with hyphen' => ['user.set-password', true];
        yield 'extension' => ['acme/shop/order.create', true];
        yield 'composer style package' => ['acme-corp/shop.v2/order.create', true];
        yield 'one part' => ['create', false];
        yield 'package without local name' => ['acme/shop', false];
        yield 'vendor only' => ['acme/order.create', false];
        yield 'upper case' => ['Acme/shop/order.create', false];
        yield 'trailing slash' => ['acme/shop/', false];
    }

    #[DataProvider('names')]
    public function testValidatesNames(string $name, bool $valid): void
    {
        self::assertSame($valid, ActionName::isValid($name));
    }

    public function testSplitsPackageAndLocalName(): void
    {
        self::assertSame('acme/shop', ActionName::package('acme/shop/order.create'));
        self::assertSame('order.create', ActionName::local('acme/shop/order.create'));
        self::assertNull(ActionName::package('user.create'));
        self::assertSame('user.create', ActionName::local('user.create'));
    }

    public function testAnExtensionRegistersNamesOfItsPackage(): void
    {
        $registry = new Registry();
        $registry->forPackage('acme/shop', static fn() => $registry->query(ListOrders::class));

        self::assertTrue($registry->catalog()->has('acme/shop/order.list'));
    }

    public function testAnExtensionNeedsItsPackageInFront(): void
    {
        $registry = new Registry();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('needs the package name in front: "acme/shop/order.create"');

        $registry->forPackage('acme/shop', static fn() => $registry->action(CreateOrder::class));
    }

    public function testAnExtensionCannotUseCoreNames(): void
    {
        $registry = new Registry();

        $this->expectException(DefinitionException::class);
        $registry->forPackage('acme/shop', static fn() => $registry->query(ListNotes::class));
    }

    public function testAnExtensionCannotUseNamesOfOtherPackages(): void
    {
        $registry = new Registry();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('must start with "foo/blog/"');

        $registry->forPackage('foo/blog', static fn() => $registry->query(ListOrders::class));
    }

    public function testTheCoreCannotUsePackageNames(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('belongs to the package acme/shop');

        new Registry()->query(ListOrders::class);
    }
}
