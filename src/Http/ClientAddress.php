<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The address of the client of a request (decision 0343): behind a
 * trusted proxy the one ForwardedMiddleware found, else REMOTE_ADDR.
 */
final class ClientAddress
{
    public const string ATTRIBUTE = 'frootbox.client_address';

    public static function of(ServerRequestInterface $request): ?string
    {
        $address = $request->getAttribute(self::ATTRIBUTE) ?? $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return \is_string($address) && '' !== $address ? $address : null;
    }
}
