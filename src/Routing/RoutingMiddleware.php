<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Routing;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnexpectedValueException;

/**
 * Answers requests for fixed routes and passes all others on.
 *
 * Route placeholders become request attributes. The matched route is
 * available as the attribute Route::class.
 */
final readonly class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Router $router,
        private ContainerInterface $container,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        if (null === $match) {
            return $handler->handle($request);
        }

        $routeHandler = $this->container->get($match->route->handler);

        if (!$routeHandler instanceof RequestHandlerInterface) {
            throw new UnexpectedValueException(\sprintf('Route handler "%s" must implement %s.', $match->route->handler, RequestHandlerInterface::class));
        }

        $request = $request->withAttribute(Route::class, $match->route);

        foreach ($match->parameters as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $routeHandler->handle($request);
    }
}
