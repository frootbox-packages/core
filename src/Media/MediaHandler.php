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
use Frootbox\Core\Http\FileRangeStream;
use Frootbox\Core\Preview\PreviewAccess;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Generates a size of an image on its first request (decision 0126) and
 * delivers the files of videos (decision 0177) and documents (decision
 * 0179) with Range. A
 * public medium is written to public/media/, so the web server delivers it
 * from then on. A medium that is not public yet, and the version of a
 * draft (decision 0147), are only shown to those who may see the media
 * library, and never written.
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
        private MediaDrafts $drafts,
        private MediaSources $sources,
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

        if (1 === preg_match(MediaUrls::VIDEO_PATTERN, $file, $match)) {
            return $this->video($request, $id, $version, $file, $match);
        }

        if (1 === preg_match(MediaUrls::DOCUMENT_PATTERN, $file, $match)) {
            return $this->document($request, $id, $version, $file, $match);
        }

        if (1 !== preg_match(MediaUrls::FILE_PATTERN, $file, $match)) {
            throw HttpException::notFound($path);
        }

        $width = (int) $match['width'];
        $height = (int) $match['height'];
        $extension = $match['extension'];
        [$medium, $private] = $this->find($request, $id, $version) ?? throw HttpException::notFound($path);

        if (
            !$medium->isImage()
            || !$this->urls->isValid($id, $version, $match['name'], $width, $height, $extension, $match['signature'])
            || !MediaUrls::allows($medium, $width)
            || !\in_array($extension, $this->urls->formats($medium), true)
            || ($height > 0 && $height > $width * 4)
        ) {
            throw HttpException::notFound($path);
        }

        $original = $this->files->original($medium->original());

        if (!is_file($original)) {
            throw HttpException::notFound($path);
        }

        $contents = $this->images->render($original, $width, $height, $medium->focusX, $medium->focusY, $extension);

        if (!$private) {
            $this->files->writePublic($id . '/' . $version . '/' . $file, $contents);
        }

        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', self::TYPES[$extension])
            ->withHeader('Cache-Control', $private ? 'private, no-store' : 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $response->getBody()->write($contents);

        return $response;
    }

    /**
     * The medium in this version and whether it is private (not public
     * yet, or a draft); null if the request may not see it.
     *
     * @return array{Medium, bool}|null
     */
    private function find(ServerRequestInterface $request, string $id, int $version): ?array
    {
        $medium = $this->media->fetchById($id);
        $draft = false;

        // Another version than the published one can only be the draft.
        if (null !== $medium && $medium->version !== $version) {
            $medium = $this->drafts->current($medium);
            $draft = true;
        }

        if (null === $medium || $medium->version !== $version) {
            return null;
        }

        $private = !$medium->public || $draft;

        // A valid preview link sees the media of drafts as well (decision 0152).
        if ($private && null === $request->getAttribute(PreviewAccess::class) && !$this->gate->allows($this->context->get()->actor, $this->catalog->get('media.show'))) {
            return null;
        }

        return [$medium, $private];
    }

    /**
     * A file of a video (decision 0177): a public one is copied to
     * public/media/ for the web server; every answer takes Range.
     *
     * @param array<array-key, string> $match
     */
    private function video(ServerRequestInterface $request, string $id, int $version, string $file, array $match): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        [$medium, $private] = $this->find($request, $id, $version) ?? throw HttpException::notFound($path);

        if (!$medium->isVideo() || !$this->urls->isValidVideo($id, $version, $match['name'], $match['source'], $match['extension'], $match['signature'])) {
            throw HttpException::notFound($path);
        }

        $source = null;

        if ('main' !== $match['source']) {
            foreach ($this->sources->of($id) as $candidate) {
                $source = strtolower($candidate->id) === $match['source'] ? $candidate : $source;
            }

            if (null === $source) {
                throw HttpException::notFound($path);
            }
        }

        $type = $source->type ?? $medium->type;
        $original = $this->files->original($source->file ?? $medium->original());

        if ((Videos::TYPES[$type] ?? null) !== $match['extension'] || !is_file($original)) {
            throw HttpException::notFound($path);
        }

        if (!$private) {
            $this->files->copyPublic($original, $id . '/' . $version . '/' . $file);
        }

        return $this->ranged($request, $original, $type, $private);
    }

    /**
     * The file of a document (decision 0179): a public one is copied to
     * public/media/ for the web server, like a video.
     *
     * @param array<array-key, string> $match
     */
    private function document(ServerRequestInterface $request, string $id, int $version, string $file, array $match): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        [$medium, $private] = $this->find($request, $id, $version) ?? throw HttpException::notFound($path);
        $original = $this->files->original($medium->original());

        if (
            !$medium->isDocument()
            || (Documents::TYPES[$medium->type] ?? null) !== $match['extension']
            || !$this->urls->isValidVideo($id, $version, $match['name'], 'main', $match['extension'], $match['signature'])
            || !is_file($original)
        ) {
            throw HttpException::notFound($path);
        }

        if (!$private) {
            $this->files->copyPublic($original, $id . '/' . $version . '/' . $file);
        }

        // Text without a charset would be read in the encoding of the browser.
        return $this->ranged($request, $original, str_starts_with($medium->type, 'text/') ? $medium->type . '; charset=utf-8' : $medium->type, $private);
    }

    /**
     * The file or the one range of it the request asks for.
     */
    private function ranged(ServerRequestInterface $request, string $file, string $type, bool $private): ResponseInterface
    {
        $size = (int) filesize($file);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', $type)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Cache-Control', $private ? 'private, no-store' : 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        // One range only; with several the whole file is a valid answer.
        if (1 === preg_match('/^bytes=(\d*)-(\d*)$/', trim($request->getHeaderLine('Range')), $range) && ('' !== $range[1] || '' !== $range[2])) {
            if ('' === $range[1]) {
                $start = max(0, $size - (int) $range[2]);
            } else {
                $start = (int) $range[1];
                $end = '' === $range[2] ? $end : min((int) $range[2], $end);
            }

            if ($start > $end) {
                return $response->withStatus(416)->withHeader('Content-Range', 'bytes */' . $size);
            }

            $status = 206;
            $response = $response->withHeader('Content-Range', \sprintf('bytes %d-%d/%d', $start, $end, $size));
        }

        return $response
            ->withStatus($status)
            ->withHeader('Content-Length', (string) max(0, $end - $start + 1))
            ->withBody(new FileRangeStream($file, $start, max(0, $end - $start + 1)));
    }
}
