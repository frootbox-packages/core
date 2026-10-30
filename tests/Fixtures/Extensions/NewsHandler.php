<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Extensions;

use Nyholm\Psr7\Response;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers /news and dispatches an event on the way, so a test can see
 * route and listener of an extension working together.
 */
final readonly class NewsHandler implements RequestHandlerInterface
{
    public function __construct(private EventDispatcherInterface $events) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $event = $this->events->dispatch(new NewsPublished('Hello'));

        $seenBy = $event instanceof NewsPublished ? $event->seenBy : [];

        return new Response(200, [], 'news: ' . implode(', ', $seenBy));
    }
}
