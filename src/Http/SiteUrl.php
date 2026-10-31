<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The address of the website from "app.url" in config/app.yaml, e.g.
 * "https://bohnenglueck.de" (decision 0104). In development it falls
 * back to the request. Live a missing entry gives null and a warning,
 * since the host of a request can be forged.
 */
final readonly class SiteUrl
{
    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
    ) {}

    public function base(ServerRequestInterface $request): ?string
    {
        $url = $this->config->get('app.url');

        if (\is_string($url) && '' !== $url) {
            if (1 !== preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#', $url)) {
                throw new ConfigException(\sprintf('"app.url" in config/app.yaml must be an address like https://example.com, not "%s".', $url));
            }

            return rtrim($url, '/');
        }

        if ($this->config->environment->isDevelopment()) {
            $uri = $request->getUri();

            return '' === $uri->getHost() ? null : $uri->getScheme() . '://' . $uri->getAuthority();
        }

        $this->logger->warning('"app.url" is missing in config/app.yaml, so pages have no canonical link.');

        return null;
    }
}
