<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Http\Exception\HttpException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Generates a size of an image on its first request (decision 0126). A
 * public medium is written to public/media/, so the web server delivers it
 * from then on. A medium that is not public yet is only shown to those who
 * may see the media library, and never written.
 */
final readonly class MediaHandler implements RequestHandlerInterface
{
    public const string ROUTE = '/media/{id:[0-9A-HJKMNP-TV-Z]{26}}/{version:[1-9][0-9]*}/{file}';

    private const array TYPES = [
        'avif' => 'image/avif',
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function __construct(
        private Media $media,
        private MediaFiles $files,
        private MediaUrls $urls,
        private Images $images,
        private Gate $gate,
        private Catalog $catalog,
        private CurrentContext $context,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $id = $request->getAttribute('id');
        $version = $request->getAttribute('version');
        $file = $request->getAttribute('file');

        if (!\is_string($id) || !\is_string($version) || !\is_string($file)) {
            throw HttpException::notFound($path);
        }

        $version = (int) $version;

        if (1 !== preg_match(MediaUrls::FILE_PATTERN, $file, $match)) {
            throw HttpException::notFound($path);
        }

        $width = (int) $match['width'];
        $height = (int) $match['height'];
        $extension = $match['extension'];
        $medium = $this->media->fetchById($id);

        if (
            null === $medium
            || $medium->version !== $version
            || !$this->urls->isValid($id, $version, $match['name'], $width, $height, $extension, $match['signature'])
            || !MediaUrls::allows($medium, $width)
            || !\in_array($extension, $this->urls->formats($medium), true)
            || ($height > 0 && $height > $width * 4)
            || (!$medium->public && !$this->gate->allows($this->context->get()->actor, $this->catalog->get('media.show')))
        ) {
            throw HttpException::notFound($path);
        }

        $original = $this->files->original($id);

        if (!is_file($original)) {
            throw HttpException::notFound($path);
        }

        $contents = $this->images->render($original, $width, $height, $medium->focusX, $medium->focusY, $extension);

        if ($medium->public) {
            $this->files->writePublic($id . '/' . $version . '/' . $file, $contents);
        }

        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', self::TYPES[$extension])
            ->withHeader('Cache-Control', $medium->public ? 'public, max-age=31536000, immutable' : 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $response->getBody()->write($contents);

        return $response;
    }
}
