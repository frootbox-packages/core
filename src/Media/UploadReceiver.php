<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Support\Message;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * The first step of an upload (decision 0126): keeps the file of the
 * field "file" and returns a token for media.create. Used by the REST API
 * and the backend alike.
 *
 * A file can come whole or in parts (decision 0177): each part sends
 * "total" (the size of the whole file) and "offset", from the second part
 * on also "upload", the token of the first answer. The first part has to
 * hold the start of the file, it tells image from video. Until the last
 * part the answer is {upload, received, complete: false}.
 */
final readonly class UploadReceiver
{
    public const string FIELD = 'file';

    /** Default for media.max_upload_mb, the limit for images. */
    public const int MAX_MB = 20;

    /** Default for media.max_video_mb. */
    public const int MAX_VIDEO_MB = 2000;

    public function __construct(
        private Uploads $uploads,
        private Gate $gate,
        private Catalog $catalog,
        private Config $config,
    ) {}

    /**
     * @return array{upload: string, name: string, size: int, complete: true}|array{upload: string, received: int, complete: false}
     *
     * @throws AccessDeniedException
     * @throws InvalidInputException
     * @throws ConflictException     A part that does not start where the upload stands
     */
    public function receive(ServerRequestInterface $request, Actor $actor): array
    {
        $descriptor = $this->catalog->get('media.create');

        if (!$this->gate->allows($actor, $descriptor)) {
            throw AccessDeniedException::for($descriptor, $actor);
        }

        $file = $request->getUploadedFiles()[self::FIELD] ?? null;
        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];
        $parts = \array_key_exists('total', $body);

        if (!$file instanceof UploadedFileInterface || \UPLOAD_ERR_NO_FILE === $file->getError()) {
            throw self::invalid(new Message('Choose a file.'));
        }

        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw self::invalid($parts
                ? new Message('The part is too large for the server, at most {max} MB.', ['max' => self::serverLimit()])
                : new Message('The file is too large, at most {max} MB.', ['max' => min($this->limit('image'), self::serverLimit())]));
        }

        if (\UPLOAD_ERR_OK !== $file->getError()) {
            throw self::invalid(new Message('The upload was interrupted. Try again.'));
        }

        try {
            $path = $file->getStream()->getMetadata('uri');
            $path = \is_string($path) && is_file($path) ? $path : null;
        } catch (Throwable) {
            $path = null;
        }

        if (null === $path) {
            throw self::invalid(new Message('The upload was interrupted. Try again.'));
        }

        $name = \is_string($body['name'] ?? null) && '' !== $body['name'] ? $body['name'] : ($file->getClientFilename() ?? 'file');

        return $parts ? $this->part($path, $name, $body) : $this->whole($path, $name);
    }

    public static function notMedia(): Message
    {
        return new Message('The file is no image (JPEG, PNG, WebP, GIF) or video (MP4, WebM).');
    }

    /**
     * @return array{upload: string, name: string, size: int, complete: true}
     */
    private function whole(string $path, string $name): array
    {
        $kind = self::kind($path) ?? throw self::invalid(self::notMedia());
        $this->checkSize((int) filesize($path), $kind);
        $token = $this->uploads->store($path, $name, move: is_uploaded_file($path));

        return $this->complete($token);
    }

    /**
     * @param array<mixed> $body
     *
     * @return array{upload: string, name: string, size: int, complete: true}|array{upload: string, received: int, complete: false}
     */
    private function part(string $path, string $name, array $body): array
    {
        $total = filter_var($body['total'] ?? null, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $offset = filter_var($body['offset'] ?? 0, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $token = $body['upload'] ?? null;

        if (false === $total) {
            throw new InvalidInputException('media.create', ['/total' => 'The size of the whole file in bytes.']);
        }

        if (false === $offset) {
            throw new InvalidInputException('media.create', ['/offset' => 'Where this part starts in the file, in bytes.']);
        }

        if (null === $token || '' === $token) {
            if (0 !== $offset) {
                throw new InvalidInputException('media.create', ['/offset' => 'The first part starts at 0.']);
            }

            $kind = self::kind($path) ?? throw self::invalid(self::notMedia());
            $this->checkSize($total, $kind);
            $token = $this->uploads->begin($path, $name, $total);
            $received = (int) filesize($path);
        } else {
            if (!\is_string($token) || null === $this->uploads->partPath($token) || $this->uploads->total($token) !== $total) {
                throw new InvalidInputException('media.create', ['/upload' => 'There is no upload with this token (any more). Start again.']);
            }

            $received = $this->uploads->append($token, $offset, $path);
        }

        if ($received > $total) {
            $this->uploads->remove($token);

            throw self::invalid(new Message('The parts are larger than the file. Start again.'));
        }

        if ($received < $total) {
            return ['upload' => $token, 'received' => $received, 'complete' => false];
        }

        $file = $this->uploads->finish($token);

        if (null === self::kind($file)) {
            $this->uploads->remove($token);

            throw self::invalid(self::notMedia());
        }

        return $this->complete($token);
    }

    /**
     * @return array{upload: string, name: string, size: int, complete: true}
     */
    private function complete(string $token): array
    {
        return ['upload' => $token, 'name' => $this->uploads->name($token), 'size' => (int) filesize((string) $this->uploads->path($token)), 'complete' => true];
    }

    /**
     * "image" or "video", null for other files.
     */
    private static function kind(string $path): ?string
    {
        return match (true) {
            Images::isImage($path) => 'image',
            Videos::isVideo($path) => 'video',
            default => null,
        };
    }

    /**
     * @throws InvalidInputException
     */
    private function checkSize(int $bytes, string $kind): void
    {
        $limit = $this->limit($kind);

        if ($bytes > $limit * 1024 * 1024) {
            throw self::invalid(new Message('The file is too large, at most {max} MB.', ['max' => $limit]));
        }
    }

    private function limit(string $kind): int
    {
        return 'video' === $kind
            ? $this->config->int('media.max_video_mb', self::MAX_VIDEO_MB)
            : $this->config->int('media.max_upload_mb', self::MAX_MB);
    }

    private static function invalid(Message $message): InvalidInputException
    {
        return new InvalidInputException('media.create', ['/' . self::FIELD => $message]);
    }

    /**
     * The size of a part that the server surely takes: at most 8 MB, with
     * room for the rest of the request below upload_max_filesize and
     * post_max_size.
     */
    public static function partBytes(): int
    {
        $limit = self::serverBytes();

        return max(64 * 1024, min(8 * 1024 * 1024, null === $limit ? \PHP_INT_MAX : $limit - 64 * 1024));
    }

    /**
     * What PHP accepts at most, in MB.
     */
    private static function serverLimit(): int
    {
        $bytes = self::serverBytes();

        return null === $bytes ? self::MAX_MB : max(1, intdiv($bytes, 1024 * 1024));
    }

    /**
     * What PHP accepts at most, in bytes; null without a limit.
     */
    private static function serverBytes(): ?int
    {
        $limits = array_filter(array_map(self::bytes(...), [(string) \ini_get('upload_max_filesize'), (string) \ini_get('post_max_size')]), static fn(int $limit): bool => $limit > 0);

        return [] === $limits ? null : min($limits);
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
