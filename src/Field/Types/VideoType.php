<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\ReferencesMedia;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaDrafts;
use Frootbox\Core\Media\MediaSources;
use Frootbox\Core\Media\MediaUrls;
use Frootbox\Core\Media\Medium;
use Frootbox\Core\Media\Videos;
use Frootbox\Core\View\Delivery;
use Frootbox\RichText\Html;
use LogicException;
use Psr\Container\ContainerInterface;
use Twig\Markup;

/**
 * A video from the media library with an optional image before it starts
 * (decision 0177):
 *
 *     {"media": "01J…", "poster": "01J…"}
 *
 * {{ field('video') }} gives a <video> with controls and one <source> per
 * file of the video, the most efficient codec first and H.264 last, each
 * with its codec in the type, so a browser skips what it cannot play.
 * The description of the medium names the player. Without a poster the
 * browser loads the first data of the film to show its first picture.
 */
#[AsFieldType('video', 'A video from the media library')]
final readonly class VideoType implements ReferencesMedia
{
    /** The width of the poster. */
    private const int POSTER_WIDTH = 1280;

    /**
     * @param ContainerInterface $container The media are loaded on first output
     */
    public function __construct(private ContainerInterface $container) {}

    public function settings(): array
    {
        return [];
    }

    public function valueSchema(Field $field): array
    {
        $id = ['type' => 'string', 'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['media'],
            'properties' => ['media' => $id, 'poster' => $id],
        ];
    }

    public function validate(mixed $value, Field $field): array
    {
        // A medium deleted later must not keep the page from being saved.
        return [];
    }

    public function media(mixed $value, Field $field): array
    {
        if (!\is_array($value) || !\is_string($value['media'] ?? null)) {
            return [];
        }

        return \is_string($value['poster'] ?? null) ? [$value['media'], $value['poster']] : [$value['media']];
    }

    public function output(mixed $value, Field $field): Markup|string
    {
        $video = \is_array($value) && \is_string($value['media'] ?? null) ? $this->medium($value['media']) : null;

        if (null === $video || !$video->isVideo()) {
            return '';
        }

        $urls = $this->service(MediaUrls::class);
        $poster = \is_string($value['poster'] ?? null) ? $this->medium($value['poster']) : null;
        $poster = null === $poster || !$poster->isImage() ? null : $poster;
        $files = [['type' => $video->type, 'codec' => $video->codec, 'url' => $urls->video($video)]];

        foreach ($this->service(MediaSources::class)->of($video->id) as $source) {
            $files[] = ['type' => $source->type, 'codec' => $source->codec, 'url' => $urls->video($video, $source)];
        }

        // Stable: among equal codecs the file of the medium comes first.
        usort($files, static fn(array $one, array $other): int => Videos::rank($one['codec']) <=> Videos::rank($other['codec']));

        $sources = '';

        foreach ($files as $file) {
            $sources .= Html::open('source', ['src' => $file['url'], 'type' => Videos::sourceType($file['type'], $file['codec'])]);
        }

        $label = $this->service(MediaDrafts::class)->seenTexts($video->id)[$this->service(Delivery::class)->contentLanguage()->code]['alt'] ?? '';

        return new Markup(Html::open('video', [
            'controls' => true,
            'playsinline' => true,
            'preload' => null === $poster ? 'metadata' : 'none',
            'width' => $video->width > 0 ? $video->width : null,
            'height' => $video->height > 0 ? $video->height : null,
            'poster' => null === $poster ? null : $this->poster($poster),
            'aria-label' => '' === trim($label) ? null : trim($label),
        ]) . $sources . '</video>', 'UTF-8');
    }

    private function medium(string $id): ?Medium
    {
        $medium = $this->service(Media::class)->fetchById($id);

        return null === $medium ? null : $this->service(MediaDrafts::class)->seen($medium);
    }

    /**
     * One size of the image in WebP, else in the format every browser shows.
     */
    private function poster(Medium $image): string
    {
        $urls = $this->service(MediaUrls::class);

        if ($image->isVector()) {
            return $urls->file($image);
        }

        $widths = MediaUrls::widths($image, self::POSTER_WIDTH);
        $extension = \in_array('webp', $urls->modernFormats(), true) ? 'webp' : MediaUrls::fallback($image);

        return $urls->url($image, $widths[\count($widths) - 1], 0, $extension);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);

        return $service instanceof $class ? $service : throw new LogicException(\sprintf('%s is not available.', $class));
    }
}
