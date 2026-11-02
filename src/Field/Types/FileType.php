<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field\Types;

use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\ReferencesMedia;
use Frootbox\Core\Field\Sample;
use Frootbox\Core\Media\Documents;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaDrafts;
use Frootbox\Core\Media\MediaUrls;
use Frootbox\Core\Media\Medium;
use Frootbox\Core\View\Delivery;
use Frootbox\RichText\Html;
use LogicException;
use NumberFormatter;
use Psr\Container\ContainerInterface;
use Twig\Markup;

/**
 * A document from the media library to download (decision 0179), with an
 * optional text of its own:
 *
 *     {"media": "01J…", "text": "Price list 2027"}
 *
 * {{ field('file') }} gives a link to the file with its format and size,
 * e.g. "Price list 2027 <small>PDF, 1.2 MB</small>". Without own text the
 * link names the title of the medium in the language of the page, else
 * its file name. For own markup use value('file').
 */
#[AsFieldType('file', 'A document from the media library to download')]
final readonly class FileType implements ReferencesMedia
{
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
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['media'],
            'properties' => [
                'media' => ['type' => 'string', 'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$'],
                'text' => ['type' => 'string', 'maxLength' => 200],
            ],
        ];
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
        $sample = Sample::of($value);

        if (null !== $sample && \is_array($value)) {
            // A file of the theme in an example (decision 0206).
            $text = \is_string($value['text'] ?? null) && '' !== trim($value['text']) ? trim($value['text']) : pathinfo($sample->name, \PATHINFO_FILENAME);
            $facts = Documents::format($sample->type) . ', ' . self::size($sample->size, $this->service(Delivery::class)->contentLanguage()->code);

            return new Markup(
                Html::open('a', ['href' => $sample->url, 'type' => $sample->type])
                    . htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                    . ' <small>' . htmlspecialchars($facts, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</small></a>',
                'UTF-8',
            );
        }

        $medium = \is_array($value) && \is_string($value['media'] ?? null) ? $this->medium($value['media']) : null;

        if (null === $medium || !$medium->isDocument()) {
            return '';
        }

        $language = $this->service(Delivery::class)->contentLanguage()->code;
        $text = \is_string($value['text'] ?? null) ? trim($value['text']) : '';
        $text = '' !== $text ? $text : trim($this->service(MediaDrafts::class)->seenTexts($medium->id)[$language]['title'] ?? '');
        $text = '' !== $text ? $text : pathinfo($medium->fileName, \PATHINFO_FILENAME);
        $facts = Documents::format($medium->type) . ', ' . self::size($medium->size, $language);

        return new Markup(
            Html::open('a', ['href' => $this->service(MediaUrls::class)->file($medium), 'type' => $medium->type])
                . htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . ' <small>' . htmlspecialchars($facts, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</small></a>',
            'UTF-8',
        );
    }

    /**
     * The size in KB or MB with the decimal mark of the language, e.g. "1,2 MB" in German.
     */
    public static function size(int $bytes, string $language): string
    {
        if ($bytes < 1024 * 1024) {
            return max(1, (int) round($bytes / 1024)) . ' KB';
        }

        $number = new NumberFormatter($language, NumberFormatter::DECIMAL);
        $number->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);
        $formatted = $number->format($bytes / 1024 / 1024);

        return (false === $formatted ? (string) round($bytes / 1024 / 1024, 1) : $formatted) . ' MB';
    }

    private function medium(string $id): ?Medium
    {
        $medium = $this->service(Media::class)->fetchById($id);

        return null === $medium ? null : $this->service(MediaDrafts::class)->seen($medium);
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
