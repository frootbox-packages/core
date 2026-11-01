<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Error;

use Frootbox\Core\Api\ApiHandler;
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
 * Turns every exception into an error page, or into problem details after
 * RFC 9457 for the REST API and clients that ask for JSON.
 *
 * Problem decides the status: HttpException keeps its own, action errors
 * get 4xx (e.g. 403, 422), everything else 500. Server errors are logged
 * as errors, client errors such as 404 only as debug.
 *
 * It runs twice (decision 0132): outside, for errors of the sign-in, and
 * behind the sign-in, so an error page of an extension (ErrorPages) knows
 * the user. The page of the core is the fallback.
 */
final readonly class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Environment $environment,
        private ErrorPageRenderer $renderer,
        private ErrorPages $pages,
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
        $showDetails = $this->environment->isDevelopment();
        $problem = Problem::from($throwable, $showDetails);
        $status = $problem->status;

        $context = [
            'status' => $status,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'message' => $throwable->getMessage(),
            'exception' => $throwable,
        ];

        // A 503 for a locked page is no failure (decision 0117).
        if ($status >= 500 && !$throwable instanceof HttpException) {
            $this->logger->error('{method} {path} failed with {status}: {message}', $context);
        } else {
            $this->logger->debug('{method} {path} answered with {status}', $context);
        }

        $response = $this->wantsJson($request)
            ? $this->responseFactory->createResponse($status)
                ->withHeader('Content-Type', 'application/problem+json')
                ->withBody($this->streamFactory->createStream(json_encode($problem->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)))
            : $this->responseFactory->createResponse($status)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->streamFactory->createStream($this->pages->render($request, $status, $throwable, $showDetails) ?? $this->renderer->render($status, $throwable, $showDetails)));

        $response = $response->withHeader('Cache-Control', 'no-store');

        foreach ($problem->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Requests to the REST API, and clients that accept JSON but not HTML.
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        if (ApiHandler::BASE === $path || str_starts_with($path, ApiHandler::BASE . '/')) {
            return true;
        }

        $accept = $request->getHeaderLine('Accept');

        return str_contains($accept, 'json') && !str_contains($accept, 'text/html');
    }
}
