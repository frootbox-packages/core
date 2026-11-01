<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Page\Slug;
use LogicException;

/**
 * Addresses of image sizes (decision 0126):
 *
 *     /media/<id>/<version>/<name>-<width>x<height>-<signature>.<extension>
 *
 * The signature is a shortened HMAC with APP_SECRET, so only sizes the
 * website itself asks for can be generated. Widths follow a fixed ladder,
 * a height of 0 keeps the ratio of the original.
 */
final readonly class MediaUrls
{
    public const string PREFIX = '/media/';

    /** @var list<int> */
    public const array WIDTHS = [160, 320, 480, 640, 960, 1280, 1600, 1920, 2560];

    public const string FILE_PATTERN = '/^(?<name>[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)-(?<width>[1-9][0-9]*)x(?<height>0|[1-9][0-9]*)-(?<signature>[0-9a-f]{16})\.(?<extension>avif|webp|jpg|png)$/';

    public function __construct(
        private Config $config,
        private Images $images,
    ) {}

    /**
     * @param int $height 0 keeps the ratio
     */
    public function url(Medium $medium, int $width, int $height, string $extension): string
    {
        $name = self::name($medium->fileName);

        return \sprintf(
            '%s%s/%d/%s-%dx%d-%s.%s',
            self::PREFIX,
            $medium->id,
            $medium->version,
            $name,
            $width,
            $height,
            $this->signature($medium->id, $medium->version, $name, $width, $height, $extension),
            $extension,
        );
    }

    public function isValid(string $id, int $version, string $name, int $width, int $height, string $extension, string $signature): bool
    {
        return hash_equals($this->signature($id, $version, $name, $width, $height, $extension), $signature);
    }

    /**
     * The widths of the ladder up to the given one, never wider than the
     * original; the original width itself if it is smaller.
     *
     * @return list<int>
     */
    public static function widths(Medium $medium, int $max = 2560): array
    {
        $limit = min($max, $medium->width);
        $widths = array_values(array_filter(self::WIDTHS, static fn(int $width): bool => $width <= $limit));

        return [] === $widths ? [$medium->width] : $widths;
    }

    /**
     * Whether a width may be generated for this medium.
     */
    public static function allows(Medium $medium, int $width): bool
    {
        return $width === $medium->width || ($width <= $medium->width && \in_array($width, self::WIDTHS, true));
    }

    /**
     * Modern formats first, then the fallback: PNG for images that may be
     * transparent, else JPEG.
     *
     * @return list<string> Extensions
     */
    public function formats(Medium $medium): array
    {
        return [...$this->modernFormats(), self::fallback($medium)];
    }

    /**
     * AVIF and WebP, as far as the server can write them.
     *
     * @return list<string> Extensions
     */
    public function modernFormats(): array
    {
        return array_values(array_filter(['avif', 'webp'], $this->images->supports(...)));
    }

    /**
     * The format every browser shows: PNG for images that may be
     * transparent, else JPEG.
     */
    public static function fallback(Medium $medium): string
    {
        return 'image/png' === $medium->type ? 'png' : 'jpg';
    }

    /**
     * The file name as part of the address, e.g. "roesterei-am-hafen".
     */
    public static function name(string $fileName): string
    {
        $base = pathinfo($fileName, \PATHINFO_FILENAME);
        $slug = substr(Slug::fromTitle($base), 0, 60);
        $slug = trim($slug, '-');

        return '' === $slug ? 'image' : $slug;
    }

    private function signature(string $id, int $version, string $name, int $width, int $height, string $extension): string
    {
        $secret = $this->config->env('APP_SECRET');

        if (null === $secret || \strlen($secret) < 16) {
            throw new LogicException('APP_SECRET is missing or shorter than 16 characters. Add a long random value to .env, e.g. from: php -r "echo bin2hex(random_bytes(32));"');
        }

        return substr(hash_hmac('sha256', \sprintf('%s/%d/%s/%dx%d.%s', $id, $version, $name, $width, $height, $extension), $secret), 0, 16);
    }
}
