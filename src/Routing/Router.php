<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Frootbox\Core\Http\Exception\HttpException;
use InvalidArgumentException;
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

    /** @var array<string, Route> */
    private array $named = [];

    private ?Dispatcher $dispatcher = null;

    /**
     * @param list<string>|string                   $methods
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function add(array|string $methods, string $path, string $handler, ?string $name = null): Route
    {
        $route = new Route(array_map(strtoupper(...), (array) $methods), $path, $handler, $name);

        if (null !== $name) {
            if (isset($this->named[$name])) {
                throw new InvalidArgumentException(\sprintf('The route name "%s" is used twice: %s and %s.', $name, $this->named[$name]->path, $path));
            }

            $this->named[$name] = $route;
        }

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

    /**
     * The path of a named route, e.g. path('backend.page', ['id' => 5])
     * gives "/cms/pages/5". Parameters that are not in the route become
     * the query string.
     *
     * @param array<string, int|string> $parameters
     */
    public function path(string $name, array $parameters = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException(\sprintf('There is no route named "%s".', $name));

        // The variant with the most optional parts that has all its placeholders.
        foreach (array_reverse(self::variants($route->path)) as $variant) {
            $path = '';
            $used = [];

            foreach ($variant as $part) {
                if (\is_string($part)) {
                    $path .= $part;

                    continue;
                }

                [$variable, $pattern] = $part;

                if (!isset($parameters[$variable])) {
                    continue 2;
                }

                $value = (string) $parameters[$variable];

                if (1 !== preg_match('~^(?:' . $pattern . ')$~', $value)) {
                    throw new InvalidArgumentException(\sprintf('"%s" does not fit the placeholder {%s} of the route "%s".', $value, $variable, $name));
                }

                // A placeholder whose pattern allows "/" keeps it, e.g. the id of a help page.
                $path .= implode('/', array_map(rawurlencode(...), explode('/', $value)));
                $used[] = $variable;
            }

            $query = array_diff_key($parameters, array_flip($used));

            return $path . ([] === $query ? '' : '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986));
        }

        throw new InvalidArgumentException(\sprintf('The route "%s" (%s) needs the parameters %s.', $name, $route->path, implode(', ', self::variables($route->path))));
    }

    /**
     * The first path segments of all routes, e.g. "api" and "cms". Pages
     * directly below the start page cannot use them (decision 0114).
     *
     * @return list<string>
     */
    public function reservedSegments(): array
    {
        return array_values(array_filter($this->routeSegments(), static fn(string $segment): bool => !\in_array($segment[0], ['.', '_'], true)));
    }

    /**
     * The first path segments of all routes, also those no slug can take
     * such as ".well-known" and "_consent" (decision 0286).
     *
     * @return list<string>
     */
    public function routeSegments(): array
    {
        $segments = [];

        foreach ($this->routes as $route) {
            $first = self::variants($route->path)[0][0] ?? null;

            if (\is_string($first) && 1 === preg_match('~^/([._]?[a-z0-9]+(?:-[a-z0-9]+)*)(?:/|$)~', $first, $match)) {
                $segments[$match[1]] = true;
            }
        }

        return array_keys($segments);
    }

    /**
     * @return list<string>
     */
    private static function variables(string $path): array
    {
        $variables = [];

        foreach (self::variants($path) as $variant) {
            foreach ($variant as $part) {
                if (\is_array($part)) {
                    $variables[$part[0]] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($variables));
    }

    /**
     * The variants of a route path, shortest first: text and
     * [placeholder, pattern] for each part.
     *
     * @return list<list<string|array{string, string}>>
     */
    private static function variants(string $path): array
    {
        $variants = [];

        foreach (new Std()->parse($path) as $variant) {
            $parts = [];

            foreach ($variant as $part) {
                if (\is_string($part)) {
                    $parts[] = $part;
                } elseif (\is_array($part) && \is_string($part[0] ?? null) && \is_string($part[1] ?? null)) {
                    $parts[] = [$part[0], $part[1]];
                } else {
                    throw new UnexpectedValueException(\sprintf('Unexpected part in the route "%s".', $path));
                }
            }

            $variants[] = $parts;
        }

        return $variants;
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
