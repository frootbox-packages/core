<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Field\Blank;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\OutputsHeadings;
use Frootbox\Core\Heading\Headings;
use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * The fields of a block, a layout or one list entry with their values,
 * as seen by field(), heading() and value() in a template.
 */
final readonly class Scope
{
    /** A name of an attribute heading() may write. */
    private const string ATTRIBUTE = '/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/';

    /**
     * @param array<string, Field> $fields
     * @param array<mixed>         $values
     * @param EditMarkup|null      $markup   In the editing mode, for users who may change the page (decision 0119)
     * @param string               $path     Where these fields are inside the block, e.g. "slides/0/"
     * @param Headings|null        $headings On a page, to give headings their level (decision 0129)
     */
    public function __construct(
        private array $fields,
        private array $values,
        private FieldTypes $types,
        private ?EditMarkup $markup = null,
        private string $path = '',
        private ?Headings $headings = null,
    ) {}

    /**
     * The same fields at a place inside the block, e.g. "slides/0/".
     */
    public function within(?EditMarkup $markup, string $path, ?Headings $headings = null): self
    {
        return new self($this->fields, $this->values, $this->types, $markup, $path, $headings);
    }

    /**
     * The field ready for output, e.g. {{ slide.field('title') }}.
     */
    public function field(string $name): mixed
    {
        $field = $this->definition($name);
        $type = $this->types->get($field->type);
        $value = $this->values[$name] ?? null;
        $extra = [];

        if ($type instanceof OutputsHeadings && null !== $this->headings) {
            $headings = $this->headings;
            $path = $this->path . $name;
            // The editor shows headings at the level they get (decision 0129).
            $extra = ['data-fb-headings' => $type->headingRanks($field), 'data-fb-heading-level' => $headings->level(1)];
            $output = $type->outputWithHeadings($value, $field, static fn(int $rank, string $content): int => $headings->take($rank, $content, $path));
        } else {
            $output = $type->output($value, $field);
        }

        // A preview shows what visitors see, with light marks (decision 0156).
        $visitor = null === $this->markup || $this->markup->anchors;

        return match (true) {
            // The paths first, so they keep the stored positions. For visitors without blank entries (decision 0144).
            $output instanceof ListValue => $visitor
                ? $output->within($this->markup, $this->path . $name . '/', $this->headings)->withoutBlank()
                : $output->within($this->markup, $this->path . $name . '/', $this->headings),
            // For visitors an empty field leaves out its element (decision 0144).
            $visitor && self::isEmpty($output) => EmptyElements::mark(),
            null === $this->markup => $output,
            \is_string($output) || $output instanceof Markup => $this->markup->field($this->path . $name, $field, $output, $value, $extra),
            default => $output,
        };
    }

    /**
     * The field as a heading at the level that fits where it stands
     * (decision 0129), e.g. {{ heading('title', 0, {class: 'hero__title'}) }}.
     * Rank 0 is the main heading of the block, 1 and deeper are below it.
     * An empty field gives nothing and does not count, the element around
     * it goes if it holds nothing else; in the editing mode
     * its placeholder shows at the level it would get.
     *
     * @param array<mixed> $attributes
     */
    public function heading(string $name, int $rank = 0, array $attributes = []): Markup
    {
        $headings = $this->headings ?? throw new ViewException(\sprintf('heading("%s") works only in blocks and layouts; write a fixed level here.', $name));
        $field = $this->definition($name);
        $plain = $this->types->get($field->type)->output($this->values[$name] ?? null, $field);

        if ((!\is_string($plain) && !$plain instanceof Markup) || $this->types->get($field->type) instanceof OutputsHeadings) {
            throw new ViewException(\sprintf('The field "%s" is no line of text and cannot be a heading.', $name));
        }

        $empty = self::isEmpty($plain);

        // Like an empty field: the element around goes if it holds nothing else (decision 0144).
        if ($empty && (null === $this->markup || $this->markup->anchors)) {
            return EmptyElements::mark();
        }

        $level = $empty ? $headings->level($rank) : $headings->take($rank, $plain instanceof Markup ? (string) $plain : Html::escape($plain), $this->path . $name);
        $tag = 'h' . $level;
        $output = $this->field($name);

        return new Markup(Html::open($tag, self::attributes($attributes)) . ($output instanceof Markup ? (string) $output : Html::escape(\is_string($output) ? $output : '')) . Html::close($tag), 'UTF-8');
    }

    /**
     * Whether none of the fields holds a value, e.g. a new list entry.
     */
    public function isBlank(): bool
    {
        return Blank::is($this->values);
    }

    /**
     * The stored value, e.g. for attributes and conditions.
     */
    public function value(string $name): mixed
    {
        $this->definition($name);

        return $this->values[$name] ?? null;
    }

    private static function isEmpty(mixed $output): bool
    {
        return '' === $output || ($output instanceof Markup && '' === (string) $output);
    }

    /**
     * @param array<mixed> $attributes
     *
     * @return array<string, string|int|bool|null>
     */
    private static function attributes(array $attributes): array
    {
        $checked = [];

        foreach ($attributes as $name => $value) {
            if (!\is_string($name) || 1 !== preg_match(self::ATTRIBUTE, $name)) {
                throw new ViewException(\sprintf('heading() got "%s" as the name of an attribute.', (string) $name));
            }

            if (null !== $value && !\is_scalar($value)) {
                throw new ViewException(\sprintf('heading() got no text for the attribute "%s".', $name));
            }

            $checked[$name] = \is_float($value) ? (string) $value : $value;
        }

        return $checked;
    }

    private function definition(string $name): Field
    {
        return $this->fields[$name] ?? throw new ViewException(\sprintf(
            'There is no field "%s". Fields: %s.',
            $name,
            [] === $this->fields ? 'none' : implode(', ', array_keys($this->fields)),
        ));
    }
}
