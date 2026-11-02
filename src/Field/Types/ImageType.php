<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\HasParts;
use Frootbox\Core\Field\ReferencesMedia;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaDrafts;
use Frootbox\Core\Media\MediaUrls;
use Frootbox\Core\Media\Medium;
use Frootbox\Core\View\Delivery;
use Frootbox\RichText\Html;
use LogicException;
use Psr\Container\ContainerInterface;
use Twig\Markup;

/**
 * An image from the media library (decision 0126):
 *
 *     {"media": "01J…", "alt": "Optional, else the one of the library",
 *      "caption": "Only with caption: true", "copyright": "Optional, else the one of the library"}
 *
 * Settings: "ratio" cuts it around its focus point, e.g. "16:9"; "sizes"
 * tells the browser how wide it is shown, e.g. "(min-width: 60rem) 50vw,
 * 100vw"; "eager" loads it at once with high priority, for the first
 * image on a page such as in a hero. Without "ratio" the <img> gets its
 * focus point as object-position, so CSS with object-fit: cover keeps it. {{ field('image') }} gives a <picture> with modern formats and
 * all widths, an SVG a single <img> (decision 0182); a deleted medium gives nothing. The editing mode shows the
 * draft of the medium, visitors the published state (decision 0147).
 *
 * Parts (decision 0203): {{ field('image', 'copyright') }} gives the
 * copyright of this place or else of the medium, {{ field('image',
 * 'caption') }} the caption, which the setting "caption: true" offers.
 */
#[AsFieldType('image', 'An image from the media library')]
final readonly class ImageType implements ReferencesMedia, HasParts
{
    public const string CAPTION = 'caption';

    public const string COPYRIGHT = 'copyright';

    public const string RATIO_PATTERN = '^[1-9][0-9]?:[1-9][0-9]?$';

    /** The width of the fallback in <img src>. */
    private const int FALLBACK_WIDTH = 960;

    /**
     * @param ContainerInterface $container The media are loaded on first output
     */
    public function __construct(private ContainerInterface $container) {}

    public function settings(): array
    {
        return [
            'ratio' => ['type' => 'string', 'pattern' => self::RATIO_PATTERN],
            'sizes' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
            'eager' => ['type' => 'boolean'],
            self::CAPTION => ['type' => 'boolean'],
        ];
    }

    public function valueSchema(Field $field): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['media'],
            'properties' => [
                'media' => ['type' => 'string', 'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$'],
                'alt' => ['type' => 'string', 'maxLength' => 500],
                self::CAPTION => ['type' => 'string', 'maxLength' => 300],
                self::COPYRIGHT => ['type' => 'string', 'maxLength' => 300],
            ],
        ];
    }

    public function parts(Field $field): array
    {
        return true === $field->setting(self::CAPTION) ? [self::CAPTION, self::COPYRIGHT] : [self::COPYRIGHT];
    }

    public function part(mixed $value, Field $field, string $part): string
    {
        if (!\is_array($value) || !\in_array($part, $this->parts($field), true)) {
            return '';
        }

        if (self::CAPTION === $part) {
            return \is_string($value[self::CAPTION] ?? null) ? trim($value[self::CAPTION]) : '';
        }

        if (\is_string($value[self::COPYRIGHT] ?? null) && '' !== trim($value[self::COPYRIGHT])) {
            return trim($value[self::COPYRIGHT]);
        }

        $id = $this->media($value, $field)[0] ?? null;
        $medium = null === $id ? null : $this->service(Media::class)->fetchById($id);

        // Nothing for a deleted medium, as the image itself is gone too.
        return null === $medium ? '' : $this->service(MediaDrafts::class)->seen($medium)->copyright;
    }

    public function validate(mixed $value, Field $field): array
    {
        // A medium deleted later must not keep the page from being saved.
        return [];
    }

    public function media(mixed $value, Field $field): array
    {
        return \is_array($value) && \is_string($value['media'] ?? null) ? [$value['media']] : [];
    }

    public function output(mixed $value, Field $field): Markup|string
    {
        $id = $this->media($value, $field)[0] ?? null;
        $medium = null === $id ? null : $this->service(Media::class)->fetchById($id);

        // A video or document in an image field shows nothing (decisions 0177, 0179).
        if (null === $medium || !\is_array($value) || !$medium->isImage()) {
            return '';
        }

        $medium = $this->service(MediaDrafts::class)->seen($medium);

        if ($medium->isVector()) {
            return $this->vector($value, $field, $medium);
        }

        $urls = $this->service(MediaUrls::class);
        $ratio = self::ratio($field, $medium);
        $widths = MediaUrls::widths($medium);
        $fallback = MediaUrls::fallback($medium);
        $sizes = \is_string($field->setting('sizes')) ? $field->setting('sizes') : '100vw';
        $srcset = static fn(string $extension): string => implode(', ', array_map(
            static fn(int $width): string => $urls->url($medium, $width, self::height($width, $ratio), $extension) . ' ' . $width . 'w',
            $widths,
        ));

        $sources = '';

        foreach ($urls->modernFormats() as $extension) {
            $sources .= Html::open('source', ['type' => 'image/' . $extension, 'srcset' => $srcset($extension), 'sizes' => $sizes]);
        }

        $eager = true === $field->setting('eager');
        $width = self::fallbackWidth($widths);
        $height = self::height($width, $ratio);
        $img = Html::open('img', [
            'src' => $urls->url($medium, $width, $height, $fallback),
            'srcset' => $srcset($fallback),
            'sizes' => $sizes,
            'width' => $width,
            'height' => 0 === $height ? max(1, (int) round($width * $medium->height / $medium->width)) : $height,
            'alt' => $this->alt($value, $medium),
            'loading' => $eager ? 'eager' : 'lazy',
            'fetchpriority' => $eager ? 'high' : null,
            'decoding' => 'async',
            'style' => null === $ratio ? self::focus($medium) : null,
        ]);

        return new Markup(Html::element('picture', $sources . $img), 'UTF-8');
    }

    /**
     * An SVG (decision 0182): one file, sharp at every width. A ratio cuts
     * it with CSS around the focus point instead of in a generated size.
     *
     * @param array<mixed> $value
     */
    private function vector(array $value, Field $field, Medium $medium): Markup
    {
        $ratio = self::ratio($field, $medium);
        $eager = true === $field->setting('eager');
        $height = null === $ratio ? $medium->height : max(1, (int) round($medium->width / $ratio));
        $focus = self::focus($medium);
        $style = null === $ratio
            ? $focus
            : \sprintf('aspect-ratio: %d / %d; object-fit: cover', $medium->width, $height) . (null === $focus ? '' : '; ' . $focus);

        return new Markup(Html::open('img', [
            'src' => $this->service(MediaUrls::class)->file($medium),
            'width' => $medium->width,
            'height' => $height,
            'alt' => $this->alt($value, $medium),
            'loading' => $eager ? 'eager' : 'lazy',
            'fetchpriority' => $eager ? 'high' : null,
            'decoding' => 'async',
            'style' => $style,
        ]), 'UTF-8');
    }

    /**
     * Width through height, null to keep the ratio of the original.
     */
    private static function ratio(Field $field, Medium $medium): ?float
    {
        $setting = $field->setting('ratio');

        if (!\is_string($setting) || 1 !== preg_match('/' . self::RATIO_PATTERN . '/', $setting)) {
            return null;
        }

        [$width, $height] = array_map(intval(...), explode(':', $setting));
        // Taller than 1:4 is not delivered (MediaHandler).
        $ratio = max(0.25, $width / $height);

        return abs($ratio - $medium->width / $medium->height) < 0.01 ? null : $ratio;
    }

    /**
     * The focus point as object-position, null in the middle.
     */
    private static function focus(Medium $medium): ?string
    {
        if (500 === $medium->focusX && 500 === $medium->focusY) {
            return null;
        }

        return \sprintf('object-position: %s%% %s%%', $medium->focusX / 10, $medium->focusY / 10);
    }

    /**
     * @return int 0 keeps the ratio of the original
     */
    private static function height(int $width, ?float $ratio): int
    {
        return null === $ratio ? 0 : max(1, (int) round($width / $ratio));
    }

    /**
     * @param list<int> $widths
     */
    private static function fallbackWidth(array $widths): int
    {
        $fitting = array_filter($widths, static fn(int $width): bool => $width <= self::FALLBACK_WIDTH);

        return [] === $fitting ? $widths[0] : max($fitting);
    }

    /**
     * @param array<mixed> $value
     */
    private function alt(array $value, Medium $medium): string
    {
        if (\is_string($value['alt'] ?? null) && '' !== trim($value['alt'])) {
            return trim($value['alt']);
        }

        $language = $this->service(Delivery::class)->contentLanguage()->code;

        return $this->service(MediaDrafts::class)->seenTexts($medium->id)[$language]['alt'] ?? '';
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
