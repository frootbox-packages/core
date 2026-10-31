<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Cache;

use Frootbox\Core\Auth\Authentication;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\ProjectPaths;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The finished HTML of published pages in var/cache/pages, by host and
 * path (decision 0107).
 *
 * Every entry lives in the folder of a generation. Clearing starts a new
 * generation and removes the old folders, so a request that is still
 * rendering an old state cannot bring it back.
 */
final class PageCache
{
    public const string HEADER = 'X-Frootbox-Cache';

    private ?string $generation = null;

    public function __construct(
        private readonly ProjectPaths $paths,
        private readonly Environment $environment,
    ) {}

    /**
     * Whether the answer to the request may come from the cache: a GET or
     * HEAD of a guest, not in development mode.
     *
     * The query does not count: only parameters that blocks declare may
     * change a page, and none do so far. Tracking parameters such as
     * utm_source get the page of the plain URL.
     */
    public function accepts(ServerRequestInterface $request): bool
    {
        return !$this->environment->isDevelopment()
            && \in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && '' === $request->getHeaderLine('Authorization')
            && null === $request->getAttribute(Authentication::class);
    }

    public function get(ServerRequestInterface $request): ?string
    {
        if (!$this->accepts($request)) {
            return null;
        }

        $file = $this->file($request);
        $html = is_file($file) ? @file_get_contents($file) : false;

        return false === $html ? null : $html;
    }

    public function put(ServerRequestInterface $request, string $html): void
    {
        if (!$this->accepts($request)) {
            return;
        }

        $file = $this->file($request);
        $directory = \dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        // Write to a temporary file first, so no request reads a half-written page.
        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (false !== @file_put_contents($temporary, $html)) {
            @rename($temporary, $file);
        }
    }

    /**
     * Starts a new generation and removes the cached pages of the old ones.
     */
    public function clear(): void
    {
        $root = $this->paths->var('cache/pages');

        if (!is_dir($root) && !@mkdir($root, 0o775, true) && !is_dir($root)) {
            return;
        }

        $generation = bin2hex(random_bytes(8));
        $temporary = $root . '/generation.' . $generation;

        if (false !== @file_put_contents($temporary, $generation)) {
            @rename($temporary, $root . '/generation');
        }

        $this->generation = $generation;

        foreach (self::entries($root) as $entry) {
            if ($entry !== $generation && is_dir($root . '/' . $entry)) {
                self::remove($root . '/' . $entry);
            }
        }
    }

    private function file(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $key = hash('sha256', strtolower($uri->getHost()) . ':' . ($uri->getPort() ?? '') . $uri->getPath());

        return $this->paths->var(\sprintf('cache/pages/%s/%s/%s.html', $this->generation(), substr($key, 0, 2), $key));
    }

    private function generation(): string
    {
        if (null === $this->generation) {
            $file = $this->paths->var('cache/pages/generation');
            $generation = is_file($file) ? @file_get_contents($file) : false;
            $this->generation = false !== $generation && 1 === preg_match('/^[a-f0-9]{16}$/', $generation) ? $generation : 'initial';
        }

        return $this->generation;
    }

    private static function remove(string $directory): void
    {
        foreach (self::entries($directory) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::remove($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * @return list<string> Names in the directory without "." and ".."
     */
    private static function entries(string $directory): array
    {
        $entries = @scandir($directory);

        return false === $entries ? [] : array_values(array_diff($entries, ['.', '..']));
    }
}
