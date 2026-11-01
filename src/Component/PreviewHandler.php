<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Http\Exception\HttpException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delivers the preview of a block or layout from its folder, or from the
 * theme that overrides it (decision 0149). Only for those who may list
 * the blocks, since a preview of a project may show a design that is not
 * public yet. Nothing is written to public/.
 */
final readonly class PreviewHandler implements RequestHandlerInterface
{
    /** An SVG opened on its own runs no scripts and loads nothing. */
    private const string SVG_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data:";

    public function __construct(
        private Components $components,
        private Gate $gate,
        private Catalog $catalog,
        private CurrentContext $context,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $package = $request->getAttribute('package');
        $kind = match ($request->getAttribute('kind')) {
            'blocks' => ComponentKind::Block,
            'layouts' => ComponentKind::Layout,
            default => null,
        };
        $name = $request->getAttribute('name');
        $file = $request->getAttribute('file');

        if (!\is_string($package) || null === $kind || !\is_string($name) || !\is_string($file) || 1 !== preg_match(Previews::FILE, $file, $match)) {
            throw HttpException::notFound($path);
        }

        $component = $this->components->get($kind, $package . '/' . $name);
        $preview = $component?->previews['' === $match['variant'] ? Variant::DEFAULT : $match['variant']] ?? null;

        if (
            null === $preview
            || basename($preview) !== $file
            || !is_file($preview)
            || !$this->gate->allows($this->context->get()->actor, $this->catalog->get(ComponentKind::Block === $kind ? 'block.list' : 'layout.list'))
        ) {
            throw HttpException::notFound($path);
        }

        $version = (string) filemtime($preview);
        $current = ($request->getQueryParams()['v'] ?? null) === $version;
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', Previews::TYPES[$match['extension']])
            // Versioned URLs change with the file; others are checked each time.
            ->withHeader('Cache-Control', $current ? 'private, max-age=31536000, immutable' : 'private, no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if ('svg' === $match['extension']) {
            $response = $response->withHeader('Content-Security-Policy', self::SVG_POLICY);
        }

        $response->getBody()->write((string) file_get_contents($preview));

        return $response;
    }
}
