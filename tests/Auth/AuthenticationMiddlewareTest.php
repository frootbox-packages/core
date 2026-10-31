<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Auth;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\Auth\Authentication;
use Frootbox\Core\Auth\AuthenticationMiddleware;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(AuthenticationMiddleware::class)]
#[CoversClass(Authentication::class)]
final class AuthenticationMiddlewareTest extends NotesTestCase
{
    private ?Context $seen = null;

    private ?ServerRequestInterface $request = null;

    public function testSignsInWithABearerToken(): void
    {
        $token = $this->token(TokenType::Api);
        $this->process($this->request()->withHeader('Authorization', 'Bearer ' . $token));

        self::assertSame(ActorType::User, $this->seen?->actor->type);
        self::assertSame(Channel::Api, $this->seen->channel);
        self::assertNotNull($this->seen->tokenId);
        self::assertInstanceOf(Authentication::class, $this->request?->getAttribute(Authentication::class));

        // Afterwards the request context is back to the guest.
        self::assertSame(ActorType::Guest, $this->get(CurrentContext::class)->get()->actor->type);
    }

    public function testSignsInWithTheSessionCookie(): void
    {
        $token = $this->token(TokenType::Session);
        $this->process($this->request()->withCookieParams([AuthenticationMiddleware::COOKIE => $token]));

        self::assertSame(ActorType::User, $this->seen?->actor->type);
        self::assertSame(Channel::Web, $this->seen->channel);
    }

    public function testAWrongCookieStaysAGuest(): void
    {
        $this->process($this->request()->withCookieParams([AuthenticationMiddleware::COOKIE => 'fbx_wrong']));

        self::assertSame(ActorType::Guest, $this->seen?->actor->type);
    }

    public function testAWrongBearerTokenIsRejected(): void
    {
        try {
            $this->process($this->request()->withHeader('Authorization', 'Bearer fbx_wrong'));
            self::fail('A wrong token was accepted.');
        } catch (HttpException $error) {
            self::assertSame(401, $error->status);
            self::assertSame(['WWW-Authenticate' => 'Bearer'], $error->headers);
        }

        self::assertNull($this->seen);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(CurrentContext::class)->set(Context::guest());
    }

    private function token(TokenType $type): string
    {
        $user = $this->runner()->run(new CreateUser('jane@example.com', 'Jane'), Context::system())->user;

        return $this->get(AccessTokens::class)->issue($user->id, $type)->token;
    }

    private function request(): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('GET', '/');
    }

    private function process(ServerRequestInterface $request): void
    {
        $context = $this->get(CurrentContext::class);
        $handler = new class ($context, function (Context $seen, ServerRequestInterface $request): void {
            $this->seen = $seen;
            $this->request = $request;
        }) implements RequestHandlerInterface {
            /**
             * @param \Closure(Context, ServerRequestInterface): void $record
             */
            public function __construct(private readonly CurrentContext $context, private readonly \Closure $record) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->record)($this->context->get(), $request);

                return new Response();
            }
        };

        $this->get(AuthenticationMiddleware::class)->process($request, $handler);
    }
}
