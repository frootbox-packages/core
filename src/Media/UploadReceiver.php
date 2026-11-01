<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Catalog;
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
 */
final readonly class UploadReceiver
{
    public const string FIELD = 'file';

    /** Default for media.max_upload_mb. */
    public const int MAX_MB = 20;

    public function __construct(
        private Uploads $uploads,
        private Gate $gate,
        private Catalog $catalog,
        private Config $config,
    ) {}

    /**
     * @return array{upload: string, name: string, size: int}
     *
     * @throws AccessDeniedException
     * @throws InvalidInputException
     */
    public function receive(ServerRequestInterface $request, Actor $actor): array
    {
        $descriptor = $this->catalog->get('media.create');

        if (!$this->gate->allows($actor, $descriptor)) {
            throw AccessDeniedException::for($descriptor, $actor);
        }

        $file = $request->getUploadedFiles()[self::FIELD] ?? null;
        $maxMb = $this->config->int('media.max_upload_mb', self::MAX_MB);

        if (!$file instanceof UploadedFileInterface || \UPLOAD_ERR_NO_FILE === $file->getError()) {
            throw self::invalid(new Message('Choose a file.'));
        }

        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) || ($file->getSize() ?? 0) > $maxMb * 1024 * 1024) {
            throw self::invalid(new Message('The file is too large, at most {max} MB.', ['max' => min($maxMb, self::serverLimit())]));
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

        if (null === $path || !Images::isImage($path)) {
            throw self::invalid(Images::notAnImage());
        }

        $name = $file->getClientFilename() ?? 'image';
        $token = $this->uploads->store($path, $name, move: is_uploaded_file($path));

        return ['upload' => $token, 'name' => Uploads::cleanName($name), 'size' => (int) filesize((string) $this->uploads->path($token))];
    }

    private static function invalid(Message $message): InvalidInputException
    {
        return new InvalidInputException('media.create', ['/' . self::FIELD => $message]);
    }

    /**
     * What PHP accepts at most, in MB.
     */
    private static function serverLimit(): int
    {
        $limits = array_filter(array_map(self::megabytes(...), [(string) \ini_get('upload_max_filesize'), (string) \ini_get('post_max_size')]), static fn(int $limit): bool => $limit > 0);

        return [] === $limits ? self::MAX_MB : min($limits);
    }

    private static function megabytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024,
            'k' => intdiv($number, 1024),
            'm' => $number,
            default => intdiv($number, 1024 * 1024),
        };
    }
}
