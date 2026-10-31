<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Http\Exception\HttpException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Signs in the user of a request:
 *
 * - "Authorization: Bearer fbx_…" for the REST API, the app and MCP.
 *   A wrong or expired token answers 401.
 * - The session cookie for the browser. A wrong one is ignored.
 *
 * Without either the request runs as a guest. The Authentication is also
 * put on the request as an attribute. The database is only touched when a
 * token comes along.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public const string COOKIE = 'frootbox_session';

    public function __construct(
        private ContainerInterface $container,
        private CurrentContext $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        [$authentication, $channel] = $this->authenticate($request);

        if (null === $authentication) {
            return $handler->handle($request);
        }

        return $this->context->runAs(
            new Context($authentication->actor, $channel, $authentication->token->id),
            static fn(): ResponseInterface => $handler->handle($request->withAttribute(Authentication::class, $authentication)),
        );
    }

    private function tokens(): AccessTokens
    {
        $tokens = $this->container->get(AccessTokens::class);

        return $tokens instanceof AccessTokens ? $tokens : throw new \UnexpectedValueException('AccessTokens are not available.');
    }

    /**
     * @return array{?Authentication, Channel}
     */
    private function authenticate(ServerRequestInterface $request): array
    {
        $header = $request->getHeaderLine('Authorization');

        if (1 === preg_match('/^Bearer\s+(\S+)$/i', $header, $match)) {
            $authentication = $this->tokens()->authenticate($match[1])
                ?? throw new HttpException(401, 'The access token is invalid or expired.', ['WWW-Authenticate' => 'Bearer']);

            return [$authentication, Channel::Api];
        }

        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;

        return [\is_string($cookie) ? $this->tokens()->authenticate($cookie) : null, Channel::Web];
    }
}
