<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ThrowingHandler implements RequestHandlerInterface
{
    public const string MESSAGE = 'Secret internal failure';

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new LogicException(self::MESSAGE);
    }
}
