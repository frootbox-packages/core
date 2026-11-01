<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Support\Message;
use Intervention\Image\Config;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes images with intervention/image (decision 0126):
 * Imagick if the extension is there, else GD.
 */
final class Images
{
    /** Media types that can be uploaded, with the extension of their files. */
    public const array TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** Larger images would need too much memory on normal webspace. */
    public const int MAX_PIXELS = 50_000_000;

    private const array FORMATS = [
        'avif' => Format::AVIF,
        'webp' => Format::WEBP,
        'jpg' => Format::JPEG,
        'png' => Format::PNG,
    ];

    private ?DriverInterface $driver = null;

    private ?ImageManager $manager = null;

    /**
     * Checks a file and writes it as original: turned upright by its EXIF
     * data, without metadata, an animation reduced to its first picture.
     *
     * @return array{type: string, width: int, height: int, size: int}|Message The facts or why not
     */
    public function prepare(string $source, string $target): array|Message
    {
        $type = (string) new \finfo(\FILEINFO_MIME_TYPE)->file($source);

        if (!isset(self::TYPES[$type])) {
            return self::notAnImage();
        }

        $size = @getimagesize($source);

        if (false === $size || $size[0] < 1 || $size[1] < 1) {
            return new Message('The file cannot be read as an image.');
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            return new Message('The image has too many pixels, at most {max} million.', ['max' => self::MAX_PIXELS / 1_000_000]);
        }

        $folder = \dirname($target);

        if (!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) {
            throw new RuntimeException(\sprintf('Could not create %s.', $folder));
        }

        try {
            $image = $this->manager()->decodePath($source);
            $format = 'image/gif' === $type ? Format::PNG : Format::create($type);
            $image->encodeUsingFormat($format, ...('image/png' === $type || 'image/gif' === $type ? [] : ['quality' => 92]))->save($target);
        } catch (Throwable) {
            return new Message('The file cannot be read as an image.');
        }

        return [
            'type' => 'image/gif' === $type ? 'image/png' : $type,
            'width' => $image->width(),
            'height' => $image->height(),
            'size' => (int) filesize($target),
        ];
    }

    public static function notAnImage(): Message
    {
        return new Message('The file is no image of type JPEG, PNG, WebP or GIF.');
    }

    /**
     * Whether a file is of a type that can be uploaded.
     */
    public static function isImage(string $path): bool
    {
        return isset(self::TYPES[(string) new \finfo(\FILEINFO_MIME_TYPE)->file($path)]);
    }

    /**
     * One size of an image: cut to the ratio of width and height around
     * the focus point, then scaled. A height of 0 keeps the ratio.
     *
     * @param int $focusX Thousandths of the width
     * @param int $focusY Thousandths of the height
     */
    public function render(string $original, int $width, int $height, int $focusX, int $focusY, string $extension): string
    {
        $image = $this->manager()->decodePath($original);
        $sourceWidth = $image->width();
        $sourceHeight = $image->height();

        if ($height > 0) {
            [$cropWidth, $cropHeight, $x, $y] = self::crop($sourceWidth, $sourceHeight, $width / $height, $focusX, $focusY);

            if ($cropWidth !== $sourceWidth || $cropHeight !== $sourceHeight) {
                $image->crop($cropWidth, $cropHeight, $x, $y);
            }

            $image->resize($width, $height);
        } elseif ($width < $sourceWidth) {
            $image->scale($width);
        }

        $format = self::FORMATS[$extension] ?? Format::JPEG;
        $options = match ($format) {
            Format::AVIF => ['quality' => 60],
            Format::WEBP => ['quality' => 80],
            Format::JPEG => ['quality' => 82],
            default => [],
        };

        return $image->encodeUsingFormat($format, ...$options)->toString();
    }

    /**
     * The box of the given ratio around the focus point, as large as fits.
     *
     * @return array{int, int, int, int} width, height, left, top
     */
    public static function crop(int $width, int $height, float $ratio, int $focusX, int $focusY): array
    {
        if ($width / $height > $ratio) {
            $cropHeight = $height;
            $cropWidth = max(1, (int) round($height * $ratio));
        } else {
            $cropWidth = $width;
            $cropHeight = max(1, (int) round($width / $ratio));
        }

        $x = (int) round($focusX / 1000 * $width - $cropWidth / 2);
        $y = (int) round($focusY / 1000 * $height - $cropHeight / 2);

        return [$cropWidth, $cropHeight, max(0, min($x, $width - $cropWidth)), max(0, min($y, $height - $cropHeight))];
    }

    /**
     * Whether the driver can write files of this extension, e.g. "avif".
     */
    public function supports(string $extension): bool
    {
        $format = self::FORMATS[$extension] ?? null;

        return null !== $format && $this->driver()->supports($format);
    }

    private function manager(): ImageManager
    {
        return $this->manager ??= new ImageManager($this->driver());
    }

    private function driver(): DriverInterface
    {
        $config = new Config(autoOrientation: true, decodeAnimation: false, strip: true);

        return $this->driver ??= \extension_loaded('imagick') ? new ImagickDriver($config) : new GdDriver($config);
    }
}
