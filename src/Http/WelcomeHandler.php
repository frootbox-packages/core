<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Frootbox;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The start page of a new website until it has a published one: says
 * that Frootbox runs and how to go on.
 */
final readonly class WelcomeHandler implements RequestHandlerInterface
{
    public function __construct(
        private Config $config,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $name = htmlspecialchars($this->config->string('app.name', 'Frootbox'), \ENT_QUOTES);
        $version = htmlspecialchars(Frootbox::VERSION, \ENT_QUOTES);
        $environment = $this->config->environment->isDevelopment() ? 'development' : 'live';
        $backend = htmlspecialchars($this->config->string('backend.path', '/cms'), \ENT_QUOTES);
        $php = htmlspecialchars(\PHP_VERSION, \ENT_QUOTES);

        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$name}</title>
            <style>
            body { font: 16px/1.6 system-ui, sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1rem; color: #222; }
            h1 { font-size: 1.75rem; margin: 0 0 .5rem; }
            dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; color: #555; }
            dt { font-weight: 600; }
            </style>
            </head>
            <body>
            <h1>{$name}</h1>
            <p>Frootbox {$version} is running. The website has no published page yet.</p>
            <p>Sign in at <a href="{$backend}">{$backend}</a> to create the first page, or add pages from a file with <code>php bin/frootbox seed</code>.</p>
            <dl>
            <dt>Environment</dt><dd>{$environment}</dd>
            <dt>PHP</dt><dd>{$php}</dd>
            </dl>
            </body>
            </html>
            HTML;

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streamFactory->createStream($html));
    }
}
