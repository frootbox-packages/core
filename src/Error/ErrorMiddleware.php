<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Frootbox\Core\Config\Environment;
use Frootbox\Core\Http\Exception\HttpException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Turns every exception into an error page.
 *
 * HttpException keeps its status, everything else becomes 500.
 */
final readonly class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Environment $environment,
        private ErrorPageRenderer $renderer,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $throwable) {
            return $this->respond($throwable);
        }
    }

    private function respond(Throwable $throwable): ResponseInterface
    {
        $status = $throwable instanceof HttpException ? $throwable->status : 500;

        if ($status >= 500) {
            // Until logging exists (Monolog), server errors go to the PHP error log.
            error_log((string) $throwable);
        }

        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streamFactory->createStream(
                $this->renderer->render($status, $throwable, $this->environment->isDevelopment()),
            ));

        if ($throwable instanceof HttpException) {
            foreach ($throwable->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
