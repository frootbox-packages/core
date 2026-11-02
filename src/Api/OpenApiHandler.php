<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Api;

use Frootbox\Core\Action\CurrentContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/openapi.json: the API description for the caller.
 */
final readonly class OpenApiHandler implements RequestHandlerInterface
{
    public function __construct(
        private OpenApi $openApi,
        private CurrentContext $context,
        private JsonResponder $json,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->respond($this->openApi->document(ApiHandler::context($this->context)->actor));
    }
}
