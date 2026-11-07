<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Runner;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /_oidc/{provider}/logout, the back channel of a provider after
 * OIDC Back-Channel Logout 1.0 (decision 0322): a form with logout_token.
 * 200 without content, 400 for a token that is no valid one. Like the
 * callbacks of the hub it runs past the password of the website.
 */
final readonly class LogoutHandler implements RequestHandlerInterface
{
    private const int SIZE = 10000;

    public function __construct(
        private Runner $runner,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $form = $request->getParsedBody();

        if (!\is_array($form) || [] === $form) {
            parse_str(\strlen($body) > self::SIZE ? '' : $body, $form);
        }

        $token = $form['logout_token'] ?? null;
        $provider = $request->getAttribute('provider');

        if (!\is_string($token) || '' === $token || !\is_string($provider)) {
            return $this->answer(400, 'invalid_request');
        }

        try {
            $this->runner->run(new BackChannelLogout($provider, $token));
        } catch (ActionException) {
            return $this->answer(400, 'invalid_request');
        }

        return $this->answer(200);
    }

    private function answer(int $status, ?string $error = null): ResponseInterface
    {
        $response = $this->responses->createResponse($status)->withHeader('Cache-Control', 'no-store');

        if (null !== $error) {
            $response = $response->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(['error' => $error], \JSON_THROW_ON_ERROR));
        }

        return $response;
    }
}
