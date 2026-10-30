<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Routing;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * A fixed route, e.g. "GET /" to a handler class.
 */
final readonly class Route
{
    /**
     * @param list<string>                          $methods
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function __construct(
        public array $methods,
        public string $path,
        public string $handler,
        public ?string $name = null,
    ) {}
}
