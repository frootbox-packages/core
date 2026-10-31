<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * Routes below a path that the installation sets in config/app.yaml,
 * e.g. the backend below "backend.path" (decision 0114):
 *
 *     $cms = $registry->mount('backend.path', '/cms');
 *     $cms->get('/', DashboardHandler::class, 'backend');
 *     $cms->get('/login', LoginHandler::class, 'backend.login');
 *
 * Build links with Router::path() and the route name, never by hand.
 */
final readonly class Mount
{
    public function __construct(
        private Registry $registry,
        public string $configKey,
        public string $default,
    ) {}

    /**
     * @param list<string>|string                   $methods
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function route(array|string $methods, string $path, string $handler, ?string $name = null): self
    {
        $this->registry->mountedRoute($this, $methods, $path, $handler, $name);

        return $this;
    }

    /**
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function get(string $path, string $handler, ?string $name = null): self
    {
        return $this->route('GET', $path, $handler, $name);
    }

    /**
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function post(string $path, string $handler, ?string $name = null): self
    {
        return $this->route('POST', $path, $handler, $name);
    }
}
