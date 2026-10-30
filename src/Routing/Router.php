<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Frootbox\Core\Http\Exception\HttpException;
use Psr\Http\Server\RequestHandlerInterface;
use UnexpectedValueException;

use function FastRoute\simpleDispatcher;

/**
 * Fixed routes of the core and of extensions, e.g. the API or the backend.
 *
 * Page URLs from the page tree are resolved later by their own middleware.
 * Path placeholders follow FastRoute, e.g. "/api/pages/{id:\d+}".
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    private ?Dispatcher $dispatcher = null;

    /**
     * @param list<string>|string                   $methods
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function add(array|string $methods, string $path, string $handler, ?string $name = null): Route
    {
        $route = new Route(array_map(strtoupper(...), (array) $methods), $path, $handler, $name);
        $this->routes[] = $route;
        $this->dispatcher = null;

        return $route;
    }

    /**
     * Adds a GET route. HEAD requests use it too.
     *
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function get(string $path, string $handler, ?string $name = null): Route
    {
        return $this->add('GET', $path, $handler, $name);
    }

    /**
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function post(string $path, string $handler, ?string $name = null): Route
    {
        return $this->add('POST', $path, $handler, $name);
    }

    /**
     * Finds the route for a request.
     *
     * Returns null if no route has this path, so other middleware can answer.
     *
     * @throws HttpException 405 if the path exists, but not for this method
     */
    public function match(string $method, string $path): ?RouteMatch
    {
        $result = $this->dispatcher()->dispatch(strtoupper($method), $path);

        return match ($result[0]) {
            Dispatcher::FOUND => $this->found($result),
            Dispatcher::METHOD_NOT_ALLOWED => throw HttpException::methodNotAllowed($method, $this->allowedMethods($result)),
            default => null,
        };
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    private function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as $index => $route) {
                $collector->addRoute($route->methods, $route->path, $index);
            }
        });
    }

    /**
     * @param array<mixed> $result
     */
    private function found(array $result): RouteMatch
    {
        $index = $result[1] ?? null;
        $variables = $result[2] ?? [];

        if (!\is_int($index) || !isset($this->routes[$index]) || !\is_array($variables)) {
            throw new UnexpectedValueException('Unexpected result from the route dispatcher.');
        }

        $parameters = [];

        foreach ($variables as $name => $value) {
            $parameters[(string) $name] = \is_scalar($value) ? (string) $value : '';
        }

        return new RouteMatch($this->routes[$index], $parameters);
    }

    /**
     * @param array<mixed> $result
     *
     * @return list<string>
     */
    private function allowedMethods(array $result): array
    {
        $methods = $result[1] ?? [];

        return \is_array($methods) ? array_values(array_filter($methods, \is_string(...))) : [];
    }
}
