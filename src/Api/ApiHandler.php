<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Api;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Normalizer;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\UnknownActionException;
use Frootbox\Core\Http\Exception\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The REST API: every action and query of every extension, by name.
 *
 * - Actions: POST /api/v1/user.create with a JSON object as input.
 * - Queries: GET /api/v1/user.show?id=5.
 * - Extensions: POST /api/v1/acme/shop/order.create.
 *
 * The answer is {"data": …}; errors are problem details (see Problem).
 * Only a Bearer token signs in. A session cookie does not count here, so
 * the API cannot be abused by cross-site requests.
 */
final readonly class ApiHandler implements RequestHandlerInterface
{
    /** Reserved for the API, pages cannot use it. */
    public const string BASE = '/api';

    public const string PREFIX = self::BASE . '/v1';

    /** Route placeholder for the name of an action or query, with its package. */
    public const string NAME = '{name:[a-z0-9][a-z0-9._/-]*}';

    public function __construct(
        private Catalog $catalog,
        private Runner $runner,
        private CurrentContext $context,
        private JsonResponder $json,
    ) {}

    /**
     * The context of an API request: the user of the Bearer token, or a
     * guest.
     */
    public static function context(CurrentContext $current): Context
    {
        $context = $current->get();

        return Channel::Api === $context->channel ? $context : Context::guest(Channel::Api);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $name = $request->getAttribute('name');
        $name = \is_string($name) ? $name : '';

        if (!$this->catalog->has($name)) {
            throw UnknownActionException::named($name);
        }

        $descriptor = $this->catalog->get($name);
        $method = $descriptor->isAction() ? 'POST' : 'GET';

        if (!\in_array($request->getMethod(), 'GET' === $method ? ['GET', 'HEAD'] : ['POST'], true)) {
            throw HttpException::methodNotAllowed($request->getMethod(), [$method]);
        }

        $result = $this->runner->call($name, RequestInput::of($request, $descriptor), self::context($this->context));

        return $this->json->respond(['data' => Normalizer::normalize($result)]);
    }
}
