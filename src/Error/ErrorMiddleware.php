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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns every exception into an error page.
 *
 * HttpException keeps its status, everything else becomes 500. Server
 * errors are logged as errors, client errors such as 404 only as debug.
 */
final readonly class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Environment $environment,
        private ErrorPageRenderer $renderer,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $throwable) {
            return $this->respond($request, $throwable);
        }
    }

    private function respond(ServerRequestInterface $request, Throwable $throwable): ResponseInterface
    {
        $status = $throwable instanceof HttpException ? $throwable->status : 500;
        $context = [
            'status' => $status,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'message' => $throwable->getMessage(),
            'exception' => $throwable,
        ];

        if ($status >= 500) {
            $this->logger->error('{method} {path} failed with {status}: {message}', $context);
        } else {
            $this->logger->debug('{method} {path} answered with {status}', $context);
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
