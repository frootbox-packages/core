<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Searchable;
use Frootbox\Core\I18n\Labels;

/**
 * Names and plain texts for the changes in the history of a page
 * (decision 0174), so a person reads "Teaser · Slides › Title" with the
 * old and the new text instead of a path and JSON. The fields come from
 * the schema of the block or layout as it is now; a field that is gone
 * from it keeps its name from the path.
 */
final readonly class ChangeLabels
{
    /** Longest text of a value, in characters. */
    public const int MAX = 500;

    /** Columns and keys of a page and its translations, with their names. */
    private const array PAGE = [
        'title' => 'Title',
        'slug' => 'Address',
        'seoTitle' => 'Title for search engines',
        'description' => 'Description for search engines',
        'layout' => 'Layout',
        'status' => 'Status',
        'publishAt' => 'Publish on',
        'unpublishAt' => 'Take offline on',
        'parentId' => 'Position in the sitemap',
        'sortKey' => 'Position in the sitemap',
        'locked' => 'Locked for visitors',
        'excludeFromSearchEngines' => 'Search engines',
        'excludeFromSiteSearch' => 'Search on the website',
        'plugin' => 'Content from a module',
    ];

    /** Values of the status of a page, with their names. */
    private const array STATUS = [
        'draft' => 'Draft',
        'published' => 'Published',
        'changed' => 'Pending changes',
        'scheduled' => 'Scheduled',
    ];

    public function __construct(
        private Components $components,
        private Labels $labels,
        private FieldTypes $types,
        private PageText $text,
    ) {}

    /**
     * @param string|null $layout the layout of the page, for its fields
     */
    public function describe(PageChange $change, ?string $layout): PageChange
    {
        $component = null === $change->block
            ? (null === $layout ? null : $this->components->layout($layout))
            : (null === $change->blockType ? null : $this->components->block($change->blockType));
        $component = null === $component ? null : $this->labels->component($component);
        [$field, $at] = $this->field($change, $component);

        return new PageChange(
            $change->kind,
            $change->state,
            $change->language,
            $change->block,
            $change->blockType,
            $change->path,
            $change->old,
            $change->new,
            null === $change->block ? null : $component->title ?? $change->blockType,
            $field,
            $this->text($change->old, $at, $change),
            $this->text($change->new, $at, $change),
        );
    }

    /**
     * The name of the changed part and the field the value belongs to,
     * null for a whole block or entry of a list.
     *
     * @return array{0: ?string, 1: Field|list<Field>|null}
     */
    private function field(PageChange $change, ?Component $component): array
    {
        $segments = '' === $change->path ? [] : array_map(self::unescape(...), explode('/', $change->path));

        if ([] === $segments) {
            return [null, null];
        }

        if (null === $change->block && 'fields' !== $segments[0] && 'areas' !== $segments[0] && 'options' !== $segments[0] && 'variant' !== $segments[0]) {
            $name = self::PAGE[$segments[0]] ?? null;

            return [null === $name ? $segments[0] : $this->labels->text($name), null];
        }

        return match ($segments[0]) {
            'fields' => $this->path(\array_slice($segments, 1), null === $component ? [] : $component->fields),
            'areas' => [$this->labels->text('Order of the blocks'), null],
            'variant' => [$this->labels->text('Variant'), null],
            'options' => [$this->option($segments[1] ?? '', $component), null],
            default => [implode(' › ', $segments), null],
        };
    }

    /**
     * Follows a path through fields and entries of lists.
     *
     * @param list<string>         $segments
     * @param array<string, Field> $fields
     *
     * @return array{0: ?string, 1: Field|list<Field>|null}
     */
    private function path(array $segments, array $fields): array
    {
        $names = [];
        $field = null;

        while ([] !== $segments) {
            $segment = array_shift($segments);

            if (null !== $field && [] !== $field->fields && (str_starts_with($segment, '#') || ctype_digit($segment))) {
                // An entry of a list: its fields, or the whole entry at the end.
                if ([] === $segments) {
                    return [implode(' › ', $names), array_values($field->fields)];
                }

                $fields = $field->fields;
                $field = null;

                continue;
            }

            $field = $fields[$segment] ?? null;
            $names[] = $field->label ?? $segment;

            if (null === $field) {
                return [implode(' › ', [...$names, ...$segments]), null];
            }
        }

        return [implode(' › ', $names), $field];
    }

    private function option(string $name, ?Component $component): string
    {
        foreach (null === $component ? [] : $component->variants as $variant) {
            if (isset($variant->options[$name])) {
                return $variant->options[$name]->label;
            }
        }

        return '' === $name ? $this->labels->text('Options') : $name;
    }

    /**
     * @param Field|list<Field>|null $at
     */
    private function text(mixed $value, Field|array|null $at, PageChange $change): ?string
    {
        if (null === $value) {
            return null;
        }

        if ('status' === $change->path && null === $change->block && \is_string($value) && isset(self::STATUS[$value])) {
            $text = $this->labels->text(self::STATUS[$value]);
        } elseif ('' === $change->path && null !== $change->block && \is_array($value)) {
            $text = $this->text->block($value);
        } elseif ($at instanceof Field && $this->types->has($at->type) && ($type = $this->types->get($at->type)) instanceof Searchable) {
            $text = $type->searchText($value, $at);
        } elseif (\is_array($at) && \is_array($value)) {
            $fields = [];

            foreach ($at as $field) {
                $fields[$field->name] = $field;
            }

            $text = $this->types->text($fields, $value);
        } else {
            $text = self::plain($value);
        }

        $text = trim((string) preg_replace('/[ \t]+/', ' ', $text));

        return mb_strlen($text) > self::MAX ? rtrim(mb_substr($text, 0, self::MAX - 1)) . '…' : $text;
    }

    /**
     * A value without a known field: scalars as they are, else the texts
     * inside, without ids and names of blocks.
     */
    private static function plain(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '✓' : '–';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        if (!\is_array($value)) {
            return '';
        }

        $texts = [];

        foreach ($value as $key => $item) {
            if (\in_array($key, ['id', 'block', 'type', 'media', 'marks', 'attrs'], true)) {
                continue;
            }

            $text = self::plain($item);

            if ('' !== $text) {
                $texts[] = $text;
            }
        }

        return implode(array_is_list($value) ? ', ' : ' ', $texts);
    }

    private static function unescape(string $segment): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }
}
