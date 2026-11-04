<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Frootbox\Core\ProjectPaths;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers 503 while a backup is restored, before anything reads the
 * database (decision 0183).
 */
final readonly class RestoreLock implements MiddlewareInterface
{
    public const string FILE = 'restore.lock';

    public function __construct(
        private ProjectPaths $paths,
        private ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!is_file($this->paths->storage(self::FILE))) {
            return $handler->handle($request);
        }

        $response = $this->responses->createResponse(503)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Retry-After', '30')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Back in a moment</title><p>This website is being restored and is back in a moment.</p></html>');

        return $response;
    }
}
