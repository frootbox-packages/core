<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Http;

use Frootbox\Core\Http\CallableRequestHandler;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(MiddlewareDispatcher::class)]
#[CoversClass(CallableRequestHandler::class)]
final class MiddlewareDispatcherTest extends TestCase
{
    public function testRunsMiddlewareInOrderBeforeFallback(): void
    {
        $dispatcher = new MiddlewareDispatcher(
            [$this->appending('a'), $this->appending('b')],
            new CallableRequestHandler(static fn(ServerRequestInterface $request): ResponseInterface => new Response(
                200,
                [],
                implode(',', self::trail($request)),
            )),
        );

        self::assertSame('a,b', (string) $dispatcher->handle(new ServerRequest('GET', '/'))->getBody());
    }

    public function testMiddlewareCanAnswerWithoutPassingOn(): void
    {
        $stop = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(204);
            }
        };

        $dispatcher = new MiddlewareDispatcher(
            [$stop],
            new CallableRequestHandler(static fn(): ResponseInterface => new Response(500)),
        );

        self::assertSame(204, $dispatcher->handle(new ServerRequest('GET', '/'))->getStatusCode());
    }

    /**
     * @return list<string>
     */
    private static function trail(ServerRequestInterface $request): array
    {
        $trail = $request->getAttribute('trail', []);

        return \is_array($trail) ? array_values(array_filter($trail, \is_string(...))) : [];
    }

    private function appending(string $name): MiddlewareInterface
    {
        return new readonly class ($name) implements MiddlewareInterface {
            public function __construct(private string $name) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $trail = $request->getAttribute('trail', []);
                $trail = \is_array($trail) ? $trail : [];
                $trail[] = $this->name;

                return $handler->handle($request->withAttribute('trail', $trail));
            }
        };
    }
}
