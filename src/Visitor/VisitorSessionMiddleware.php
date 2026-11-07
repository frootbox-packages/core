<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Visitor;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Config\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts the visitor session of a request of a browser and writes it at
 * the end (decision 0312). A request with a session carries the
 * attribute VisitorSession::class, so the page cache stays out of it.
 * Requests with an access token (REST API, MCP) have no session.
 */
final readonly class VisitorSessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private VisitorSession $session,
        private Config $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $database = $this->config->get('database.url');

        if (!\is_string($database) || '' === $database || '' !== $request->getHeaderLine('Authorization')) {
            return $handler->handle($request);
        }

        $cookie = $request->getCookieParams()[VisitorSession::COOKIE] ?? null;
        $this->session->begin(\is_string($cookie) ? $cookie : null);

        try {
            try {
                if ($this->session->active()) {
                    $request = $request->withAttribute(VisitorSession::class, true);
                }
            } catch (TableNotFoundException) {
                // Not migrated yet.
                $this->session->end();

                return $handler->handle($request);
            }

            $response = $handler->handle($request);

            foreach ($this->session->finish('https' === $request->getUri()->getScheme()) as $header) {
                $response = $response->withAddedHeader('Set-Cookie', $header);
            }

            if ($this->session->active() && str_starts_with($response->getHeaderLine('Content-Type'), 'text/html')) {
                // A page for this visitor only, never for a shared cache.
                $response = $response->withHeader('Cache-Control', 'private, no-store');
            }

            return $response;
        } finally {
            $this->session->end();
        }
    }
}
