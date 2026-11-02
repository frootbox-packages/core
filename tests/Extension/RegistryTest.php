<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Extension;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\Mount;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Routing\Router;
use Frootbox\Core\Tests\Fixtures\Error\SilentErrorPage;
use Frootbox\Core\Tests\Fixtures\Error\StatusErrorPage;
use Frootbox\Core\Tests\Support\TextHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Registry::class)]
#[CoversClass(Mount::class)]
final class RegistryTest extends TestCase
{
    public function testMountsRoutesBelowAConfiguredPath(): void
    {
        $registry = new Registry();
        $registry->mount('backend.path', '/cms')
            ->get('/', TextHandler::class, 'backend')
            ->get('/login', TextHandler::class, 'backend.login');

        $router = new Router();
        $registry->applyRoutes($router, new Config(Environment::Development, []));
        self::assertSame('/cms/login', $router->path('backend.login'));
        self::assertSame('/cms', $router->path('backend'));

        $router = new Router();
        $registry->applyRoutes($router, new Config(Environment::Development, ['backend' => ['path' => '/intern/redaktion/']]));
        self::assertSame('/intern/redaktion/login', $router->path('backend.login'));
        self::assertSame(['intern'], $router->reservedSegments());
    }

    public function testRefusesWrongMountPaths(): void
    {
        $registry = new Registry();
        $registry->mount('backend.path', '/cms')->get('/', TextHandler::class);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('"backend.path" must be a path such as "/cms"');

        $registry->applyRoutes(new Router(), new Config(Environment::Development, ['backend' => ['path' => 'CMS']]));
    }

    public function testCollectsItemsByType(): void
    {
        $registry = new Registry();
        $first = new stdClass();
        $second = new stdClass();
        $registry->add(stdClass::class, $first);
        $registry->forPackage('acme/site', static fn() => $registry->add(stdClass::class, $second));

        self::assertSame([$first, $second], $registry->items(stdClass::class));
        self::assertSame([], $registry->items(Registry::class));
    }

    public function testResolvesImportsAndAssetsWithinThePackage(): void
    {
        $registry = new Registry();
        $registry->import('@frootbox/core', 'assets/core.js');
        $registry->forPackage('acme/site', static function () use ($registry): void {
            $registry->import('lit', 'assets/build/lit.js');
            $registry->asset('assets/shop.css', 'backend');
        }, '/packages/site');

        self::assertSame(['@frootbox/core' => \dirname(__DIR__, 2) . '/assets/core.js', 'lit' => '/packages/site/assets/build/lit.js'], $registry->imports());
        self::assertSame(['/packages/site/assets/shop.css'], $registry->assets('backend'));
        self::assertSame([], $registry->assets('site'));
    }

    public function testAnImportBelongsToOnePackage(): void
    {
        $registry = new Registry();
        $registry->forPackage('acme/one', static fn() => $registry->import('lit', 'assets/lit.js'), '/one');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('The import "lit" is registered by acme/one and acme/two.');

        $registry->forPackage('acme/two', static fn() => $registry->import('lit', 'assets/lit.js'), '/two');
    }

    public function testKeepsErrorPagesOnceAndInOrder(): void
    {
        $registry = new Registry();
        $registry->errorPage(StatusErrorPage::class);
        $registry->errorPage(SilentErrorPage::class);
        $registry->errorPage(StatusErrorPage::class);

        self::assertSame([StatusErrorPage::class, SilentErrorPage::class], $registry->errorPages());
    }
}
