<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Routing;

use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Routing\Route;
use Frootbox\Core\Routing\RouteMatch;
use Frootbox\Core\Routing\Router;
use Frootbox\Core\Tests\Support\TextHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouteMatch::class)]
#[CoversClass(HttpException::class)]
final class RouterTest extends TestCase
{
    public function testMatchesRouteWithPlaceholders(): void
    {
        $router = new Router();
        $router->get('/items/{id:\d+}', TextHandler::class, 'item');

        $match = $router->match('GET', '/items/42');

        self::assertNotNull($match);
        self::assertSame('item', $match->route->name);
        self::assertSame(['id' => '42'], $match->parameters);
    }

    public function testHeadUsesGetRoute(): void
    {
        $router = new Router();
        $router->get('/', TextHandler::class);

        self::assertNotNull($router->match('HEAD', '/'));
    }

    public function testReturnsNullForUnknownPath(): void
    {
        $router = new Router();
        $router->get('/', TextHandler::class);

        self::assertNull($router->match('GET', '/unknown'));
    }

    public function testThrows405ForWrongMethod(): void
    {
        $router = new Router();
        $router->post('/form', TextHandler::class);

        try {
            $router->match('GET', '/form');
            self::fail('Expected a 405.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->status);
            self::assertSame(['Allow' => 'POST'], $exception->headers);
        }
    }

    public function testRoutesAddedLaterAreFound(): void
    {
        $router = new Router();
        $router->get('/', TextHandler::class);
        self::assertNull($router->match('GET', '/later'));

        $router->get('/later', TextHandler::class);

        self::assertNotNull($router->match('GET', '/later'));
        self::assertCount(2, $router->routes());
    }
}
