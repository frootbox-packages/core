<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Routing;

/**
 * A route that matched a request, with the values of its placeholders.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public Route $route,
        public array $parameters = [],
    ) {}
}
