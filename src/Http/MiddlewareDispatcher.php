<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs a request through a list of PSR-15 middleware.
 *
 * The first middleware runs first. If all of them pass the request on, the
 * fallback handler answers it.
 */
final readonly class MiddlewareDispatcher implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $fallback,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleFrom(0, $request);
    }

    private function handleFrom(int $index, ServerRequestInterface $request): ResponseInterface
    {
        $middleware = $this->middleware[$index] ?? null;

        if (null === $middleware) {
            return $this->fallback->handle($request);
        }

        return $middleware->process(
            $request,
            new CallableRequestHandler(fn(ServerRequestInterface $next): ResponseInterface => $this->handleFrom($index + 1, $next)),
        );
    }
}
