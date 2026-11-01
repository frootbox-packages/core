<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\ProjectPaths;
use RuntimeException;

/**
 * Files on their way into the media library (decision 0126): an upload
 * endpoint keeps the file here under a token, media.create takes the
 * token. Uploads that nobody takes are removed after a day. Each upload
 * knows the SHA-256 of its bytes, so the same file is found again
 * (decision 0146).
 */
final readonly class Uploads
{
    public const int LIFETIME = 86400;

    private const string PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(private ProjectPaths $paths) {}

    /**
     * Keeps a file and returns its token.
     *
     * @param string $source A file that is moved (an uploaded file) or copied
     */
    public function store(string $source, string $name, bool $move = true): string
    {
        $token = bin2hex(random_bytes(16));
        $folder = $this->folder();

        if (!is_dir($folder) && !mkdir($folder, 0o775, true) && !is_dir($folder)) {
            throw new RuntimeException(\sprintf('Could not create %s.', $folder));
        }

        $target = $folder . '/' . $token;
        $done = $move ? rename($source, $target) : copy($source, $target);

        if (!$done) {
            throw new RuntimeException('Could not keep the upload.');
        }

        $meta = ['name' => self::cleanName($name), 'hash' => (string) hash_file('sha256', $target)];
        file_put_contents($target . '.json', json_encode($meta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        return $token;
    }

    /**
     * The file of a token, null if there is none (any more).
     */
    public function path(string $token): ?string
    {
        if (1 !== preg_match(self::PATTERN, $token)) {
            return null;
        }

        $path = $this->folder() . '/' . $token;

        return is_file($path) ? $path : null;
    }

    /**
     * The original file name of the upload.
     */
    public function name(string $token): string
    {
        $name = $this->meta($token)['name'] ?? null;

        return \is_string($name) ? $name : 'image';
    }

    /**
     * The SHA-256 of the uploaded bytes.
     */
    public function hash(string $token): ?string
    {
        $hash = $this->meta($token)['hash'] ?? null;
        $path = $this->path($token);

        if (\is_string($hash) && 1 === preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return $hash;
        }

        return null === $path ? null : (string) hash_file('sha256', $path);
    }

    public function remove(string $token): void
    {
        $path = $this->path($token);

        if (null !== $path) {
            @unlink($path);
            @unlink($path . '.json');
        }
    }

    /**
     * Removes uploads older than a day.
     *
     * @return int How many
     */
    public function prune(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;

        $files = glob($this->folder() . '/*');

        foreach (false === $files ? [] : $files as $file) {
            if (is_file($file) && filemtime($file) < $now - self::LIFETIME) {
                @unlink($file);
                $removed += str_ends_with($file, '.json') ? 0 : 1;
            }
        }

        return $removed;
    }

    /**
     * Only the last part of a path, without control characters.
     */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\\x00-\\x1f\\x7f]/u', '', basename(str_replace('\\', '/', $name))));

        return '' === $name ? 'image' : mb_substr($name, 0, 200);
    }

    /**
     * @return array<mixed>
     */
    private function meta(string $token): array
    {
        $path = $this->path($token);
        $meta = null === $path ? false : @file_get_contents($path . '.json');
        $data = false === $meta ? null : json_decode($meta, true);

        return \is_array($data) ? $data : [];
    }

    private function folder(): string
    {
        return $this->paths->storage('private/uploads');
    }
}
