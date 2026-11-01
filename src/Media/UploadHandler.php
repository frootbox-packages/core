<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Api\ApiHandler;
use Frootbox\Core\Api\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/uploads with a multipart field "file": answers
 * {"data": {"upload": "…", "name": "…", "size": …}}; the token then goes
 * to media.create.
 */
final readonly class UploadHandler implements RequestHandlerInterface
{
    public const string PATH = ApiHandler::PREFIX . '/uploads';

    public function __construct(
        private UploadReceiver $receiver,
        private CurrentContext $context,
        private JsonResponder $json,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json->respond(['data' => $this->receiver->receive($request, ApiHandler::context($this->context)->actor)], 201);
    }
}
