<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\Slug;
use LogicException;

/**
 * Addresses of image sizes (decision 0126), of the files of videos with
 * video() (decision 0177) and of documents with file() (decision 0179):
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

    /** A file of a video (decision 0177): "main" for the one of the medium, else the id of the source. */
    public const string VIDEO_PATTERN = '/^(?<name>[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)-(?<source>main|[0-9a-z]{26})-(?<signature>[0-9a-f]{16})\.(?<extension>mp4|webm)$/';

    /** The file of a document (decision 0179). */
    public const string DOCUMENT_PATTERN = '/^(?<name>[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)-(?<source>main)-(?<signature>[0-9a-f]{16})\.(?<extension>pdf|docx|xlsx|pptx|odt|ods|odp|txt|csv|zip)$/';

    public function __construct(
        private Config $config,
        private Images $images,
        private Languages $languages,
    ) {}

    /**
     * @param int $height 0 keeps the ratio
     */
    public function url(Medium $medium, int $width, int $height, string $extension): string
    {
        $name = self::name($medium->fileName, $this->languages->default()->code);

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

    /**
     * The address of a file of a video: its own or one of its sources.
     *
     *     /media/<id>/<version>/<name>-<main or source>-<signature>.<extension>
     */
    public function video(Medium $medium, ?MediaSource $source = null): string
    {
        $name = self::name($medium->fileName, $this->languages->default()->code);
        $key = null === $source ? 'main' : strtolower($source->id);
        $extension = Videos::TYPES[$source->type ?? $medium->type] ?? 'mp4';

        return \sprintf(
            '%s%s/%d/%s-%s-%s.%s',
            self::PREFIX,
            $medium->id,
            $medium->version,
            $name,
            $key,
            $this->signature($medium->id, $medium->version, $name . '-' . $key, 0, 0, $extension),
            $extension,
        );
    }

    /**
     * The address of the file of a document (decision 0179), built like
     * that of a video.
     *
     *     /media/<id>/<version>/<name>-main-<signature>.<extension>
     */
    public function file(Medium $medium): string
    {
        $name = self::name($medium->fileName, $this->languages->default()->code);
        $extension = Documents::TYPES[$medium->type] ?? 'bin';

        return \sprintf(
            '%s%s/%d/%s-main-%s.%s',
            self::PREFIX,
            $medium->id,
            $medium->version,
            $name,
            $this->signature($medium->id, $medium->version, $name . '-main', 0, 0, $extension),
            $extension,
        );
    }

    public function isValidVideo(string $id, int $version, string $name, string $source, string $extension, string $signature): bool
    {
        return hash_equals($this->signature($id, $version, $name . '-' . $source, 0, 0, $extension), $signature);
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
     * The file name as part of the address, e.g. "roesterei-am-hafen",
     * written by the rules of the default language (decision 0134).
     */
    public static function name(string $fileName, string $language = ''): string
    {
        $base = pathinfo($fileName, \PATHINFO_FILENAME);
        $slug = substr(Slug::latin($base, $language), 0, 60);
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
