<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Asset\PublicAssets;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\Sample;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Theme\ThemeChain;
use RuntimeException;

/**
 * Turns an example of a block into content for the design page (decision
 * 0206): its texts translated through the block's package and the themes,
 * files of the themes in place of media.
 */
final readonly class ExampleContent
{
    /** Media types of sample files by extension. */
    private const array TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webm' => 'video/webm',
        'mp4' => 'video/mp4',
        'pdf' => 'application/pdf',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
    ];

    public function __construct(
        private Components $components,
        private ThemeChain $themes,
        private Extensions $extensions,
        private PublicAssets $assets,
        private Translator $translator,
    ) {}

    /**
     * A block as on a page, for ComponentRenderer::block().
     *
     * @param array<mixed> $example
     *
     * @return array<string, mixed>
     */
    public function block(Component $block, ?string $variant, array $example, string $language): array
    {
        return [
            'block' => $block->name,
            'variant' => $variant,
            'options' => \is_array($example['options'] ?? null) ? $example['options'] : [],
            'fields' => $this->fields(\is_array($example['fields'] ?? null) ? $example['fields'] : [], $block->fields, $block, $language),
            'areas' => $this->areas(\is_array($example['areas'] ?? null) ? $example['areas'] : [], $block, $language),
        ];
    }

    /**
     * @param array<mixed> $areas
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function areas(array $areas, Component $owner, string $language): array
    {
        $result = [];

        foreach ($areas as $area => $blocks) {
            $result[(string) $area] = [];

            foreach (\is_array($blocks) ? $blocks : [] as $data) {
                $name = \is_array($data) && \is_string($data['block'] ?? null) ? $data['block'] : null;

                if (null === $name) {
                    continue;
                }

                // Short names belong to the package of the block around them.
                $name = str_contains($name, '/') ? $name : $owner->package() . '/' . $name;
                $block = $this->components->block($name) ?? throw new RuntimeException(\sprintf('The example names the block "%s", which does not exist.', $name));
                $result[(string) $area][] = $this->block($block, \is_string($data['variant'] ?? null) ? $data['variant'] : null, $data, $language);
            }
        }

        return $result;
    }

    /**
     * @param array<mixed>         $values
     * @param array<string, Field> $fields
     *
     * @return array<string, mixed>
     */
    private function fields(array $values, array $fields, Component $block, string $language): array
    {
        $result = [];

        foreach ($values as $name => $value) {
            $field = $fields[$name] ?? null;
            $result[(string) $name] = null === $field ? $value : $this->value($value, $field, $block, $language);
        }

        return $result;
    }

    private function value(mixed $value, Field $field, Component $block, string $language): mixed
    {
        $t = fn(mixed $text): mixed => \is_string($text) ? $this->translator->in($language, $text, $this->packages($block)) : $text;

        return match ($field->type) {
            'headline', 'text' => $t($value),
            'richtext' => $this->richText($value, $t),
            'link' => \is_array($value) ? [...$value, 'text' => $t($value['text'] ?? null)] : $value,
            'list' => \is_array($value) ? array_map(fn(mixed $entry): mixed => \is_array($entry) ? $this->fields($entry, $field->fields, $block, $language) : $entry, $value) : $value,
            'table' => \is_array($value) && \is_array($value['rows'] ?? null)
                ? [...$value, 'rows' => array_map(fn(mixed $row): mixed => \is_array($row) ? array_map(fn(mixed $cell): mixed => \is_array($cell) ? array_map(fn(mixed $node): mixed => $this->richText($node, $t), $cell) : $t($cell), $row) : $row, $value['rows'])]
                : $value,
            'image', 'video', 'file' => $this->media($value, $block, $t),
            default => $value,
        };
    }

    /**
     * The texts of a rich text, translated one by one.
     *
     * @param \Closure(mixed): mixed $t
     */
    private function richText(mixed $node, \Closure $t): mixed
    {
        if (!\is_array($node)) {
            return $node;
        }

        if ('text' === ($node['type'] ?? null) && \is_string($node['text'] ?? null)) {
            return [...$node, 'text' => $t($node['text'])];
        }

        return \is_array($node['content'] ?? null) ? [...$node, 'content' => array_map(fn(mixed $child): mixed => $this->richText($child, $t), $node['content'])] : $node;
    }

    /**
     * A file of the themes in place of a medium, with its texts translated.
     *
     * @param \Closure(mixed): mixed $t
     */
    private function media(mixed $value, Component $block, \Closure $t): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        foreach (['alt', 'caption', 'copyright', 'text'] as $key) {
            if (isset($value[$key])) {
                $value[$key] = $t($value[$key]);
            }
        }

        if (!\is_string($value[Sample::KEY] ?? null)) {
            return $value;
        }

        $file = $this->file($value[Sample::KEY], $block);
        $poster = \is_string($value['poster'] ?? null) ? $this->assets->url($this->file($value['poster'], $block)) : null;
        $type = self::TYPES[strtolower(pathinfo($file, \PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        $size = str_starts_with($type, 'image/') && 'image/svg+xml' !== $type ? getimagesize($file) : false;
        unset($value['poster']);

        return [...$value, Sample::KEY => new Sample(
            $this->assets->url($file),
            $type,
            basename($file),
            (int) filesize($file),
            false === $size ? 0 : $size[0],
            false === $size ? 0 : $size[1],
            $poster,
        )];
    }

    /**
     * The file in assets/ of a theme of the chain, the child first, else
     * of the package of the block.
     */
    private function file(string $path, Component $block): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_.-]+)*\.[A-Za-z0-9]+$/', $path) || str_contains($path, '..')) {
            throw new RuntimeException(\sprintf('The sample "%s" is no path within assets/.', $path));
        }

        $folders = array_map(static fn($theme): string => $theme->path, $this->themes->themes);

        foreach ($this->extensions->packages as $package) {
            if ($package->name === $block->package()) {
                $folders[] = $package->path;
            }
        }

        foreach ($folders as $folder) {
            $file = $folder . '/' . PublicAssets::FOLDER . '/' . $path;

            if (is_file($file)) {
                return $file;
            }
        }

        throw new RuntimeException(\sprintf('The sample "%s" is in assets/ of no theme and not of %s.', $path, $block->package()));
    }

    /**
     * The package of the block and the themes, for the translations.
     *
     * @return list<string>
     */
    private function packages(Component $block): array
    {
        return array_values(array_unique([$block->package(), ...array_map(static fn($theme): string => $theme->name, $this->themes->themes)]));
    }
}
