<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Config\Environment;
use Frootbox\Core\Http\Exception\HttpException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /favicon.ico, /apple-touch-icon.png, /icon-192.png, /icon-512.png
 * and /manifest.webmanifest (decision 0318), made from the icon of the
 * theme; 404 without one. Live a day in the cache of the browser, the
 * tags in the head carry a version for a changed icon.
 */
final readonly class SiteIconHandler implements RequestHandlerInterface
{
    public function __construct(
        private SiteIcon $icon,
        private Environment $environment,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $version = $this->icon->version() ?? throw HttpException::notFound($path);

        if (SiteIcon::MANIFEST === $path) {
            $body = $this->icon->manifest() ?? throw HttpException::notFound($path);
            $type = 'application/manifest+json';
        } else {
            $file = $this->icon->file($path) ?? throw HttpException::notFound($path);
            $body = (string) file_get_contents($file);
            $type = str_ends_with($path, '.ico') ? 'image/x-icon' : 'image/png';
        }

        $etag = '"' . $version . '"';
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', $type)
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $this->environment->isDevelopment() ? 'no-cache' : 'public, max-age=86400');

        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return $response->withStatus(304);
        }

        return $response->withBody($this->streams->createStream($body));
    }
}
