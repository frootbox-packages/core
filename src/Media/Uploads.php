<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\ProjectPaths;
use RuntimeException;

/**
 * Files on their way into the media library (decision 0126): an upload
 * endpoint keeps the file here under a token, media.create takes the
 * token. A file can also come in parts (decision 0177), they wait as
 * <token>.part. Uploads that nobody takes are removed after a day. Each upload
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
     * Starts an upload in parts with its first part (decision 0177) and
     * returns the token. The parts are added with append() and become a
     * normal upload with finish().
     *
     * @param string $part A file with the first bytes, it is copied
     */
    public function begin(string $part, string $name, int $total): string
    {
        $token = bin2hex(random_bytes(16));
        $folder = $this->folder();

        if (!is_dir($folder) && !mkdir($folder, 0o775, true) && !is_dir($folder)) {
            throw new RuntimeException(\sprintf('Could not create %s.', $folder));
        }

        if (!copy($part, $folder . '/' . $token . '.part')) {
            throw new RuntimeException('Could not keep the upload.');
        }

        $meta = ['name' => self::cleanName($name), 'total' => $total];
        file_put_contents($folder . '/' . $token . '.json', json_encode($meta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        return $token;
    }

    /**
     * Adds the next part; it has to start where the parts so far end.
     *
     * @return int The bytes received so far
     *
     * @throws ConflictException With the bytes received so far, to go on from there
     */
    public function append(string $token, int $offset, string $part): int
    {
        $path = $this->partPath($token) ?? throw new RuntimeException('There is no upload in parts with this token.');
        $target = fopen($path, 'ab');
        $source = fopen($part, 'rb');

        if (false === $target || false === $source) {
            throw new RuntimeException('Could not keep the upload.');
        }

        try {
            flock($target, \LOCK_EX);
            clearstatcache(true, $path);
            $received = (int) filesize($path);

            if ($offset !== $received) {
                throw ConflictException::upload($received);
            }

            stream_copy_to_stream($source, $target);
            fflush($target);
            clearstatcache(true, $path);

            return (int) filesize($path);
        } finally {
            flock($target, \LOCK_UN);
            fclose($target);
            fclose($source);
        }
    }

    /**
     * The parts so far of an upload in parts, null if there is none.
     */
    public function partPath(string $token): ?string
    {
        if (1 !== preg_match(self::PATTERN, $token)) {
            return null;
        }

        $path = $this->folder() . '/' . $token . '.part';

        return is_file($path) ? $path : null;
    }

    /**
     * The size of the whole file an upload in parts announced.
     */
    public function total(string $token): ?int
    {
        $total = $this->meta($token, '.part')['total'] ?? null;

        return \is_int($total) ? $total : null;
    }

    /**
     * Turns the parts into a normal upload once all are there.
     *
     * @return string The file
     */
    public function finish(string $token): string
    {
        $part = $this->partPath($token) ?? throw new RuntimeException('There is no upload in parts with this token.');
        $target = $this->folder() . '/' . $token;

        if (!rename($part, $target)) {
            throw new RuntimeException('Could not keep the upload.');
        }

        $meta = ['name' => $this->name($token), 'hash' => (string) hash_file('sha256', $target)];
        file_put_contents($target . '.json', json_encode($meta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        return $target;
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
        $meta = $this->meta($token);
        $name = ([] === $meta ? $this->meta($token, '.part') : $meta)['name'] ?? null;

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
        $path = $this->path($token) ?? $this->partPath($token);

        if (null !== $path) {
            @unlink($path);
            @unlink($this->folder() . '/' . $token . '.json');
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
    private function meta(string $token, string $suffix = ''): array
    {
        $path = '' === $suffix ? $this->path($token) : $this->partPath($token);
        $path = null === $path ? null : $this->folder() . '/' . $token;
        $meta = null === $path ? false : @file_get_contents($path . '.json');
        $data = false === $meta ? null : json_decode($meta, true);

        return \is_array($data) ? $data : [];
    }

    private function folder(): string
    {
        return $this->paths->storage('private/uploads');
    }
}
