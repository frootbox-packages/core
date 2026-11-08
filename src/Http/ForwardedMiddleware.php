<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Frootbox\Core\Config\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Takes the client, the scheme and the port from X-Forwarded-For,
 * -Proto and -Port, but only when the request comes from a proxy in
 * "http.trusted_proxies" (decision 0343). The host stays: addresses for
 * links come from app.url.
 */
final readonly class ForwardedMiddleware implements MiddlewareInterface
{
    public function __construct(private Config $config) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle(self::apply($request, TrustedProxies::fromConfig($this->config)));
    }

    public static function apply(ServerRequestInterface $request, TrustedProxies $proxies): ServerRequestInterface
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (!$proxies->any() || !\is_string($remote) || !$proxies->trusts($remote)) {
            return $request;
        }

        $request = $request->withAttribute(ClientAddress::ATTRIBUTE, self::client($request, $proxies, $remote));
        $proto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));

        if ('http' !== $proto && 'https' !== $proto) {
            return $request;
        }

        $port = trim(explode(',', $request->getHeaderLine('X-Forwarded-Port'))[0]);
        $port = 1 === preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port < 65536 ? (int) $port : null;
        $uri = $request->getUri()->withScheme($proto)->withPort($port);

        return $request->withUri($uri, true);
    }

    /**
     * From the right: the first address that is not a trusted proxy. A
     * broken entry ends the search with the last proxy before it.
     */
    private static function client(ServerRequestInterface $request, TrustedProxies $proxies, string $remote): string
    {
        $client = $remote;
        $chain = array_reverse(array_map(trim(...), explode(',', $request->getHeaderLine('X-Forwarded-For'))));

        foreach ($chain as $entry) {
            if ('' === $entry) {
                continue;
            }

            // IPv6 in brackets, an IPv4 with a port.
            $entry = preg_replace('/^\[([^\]]+)\](?::[0-9]+)?$|^([0-9.]+):[0-9]+$/D', '$1$2', $entry) ?? $entry;

            if (false === @inet_pton($entry)) {
                break;
            }

            $client = $entry;

            if (!$proxies->trusts($entry)) {
                break;
            }
        }

        return $client;
    }
}
