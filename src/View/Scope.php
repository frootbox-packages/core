<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Field\Blank;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\HasParts;
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
     * The field ready for output, e.g. {{ slide.field('title') }}, or one
     * part of it, e.g. {{ field('image', 'caption') }} (decision 0203).
     */
    public function field(string $name, ?string $part = null): mixed
    {
        $field = $this->definition($name);
        $type = $this->types->get($field->type);
        $value = $this->values[$name] ?? null;
        $extra = $type instanceof HasParts ? ['data-fb-parts' => implode(' ', $type->parts($field))] : [];

        if (null !== $part) {
            return $this->part($name, $field, $type, $value, $part, $extra);
        }

        if ($type instanceof OutputsHeadings && null !== $this->headings) {
            $headings = $this->headings;
            $path = $this->path . $name;
            // The editor shows headings at the level they get (decision 0129).
            $extra = ['data-fb-headings' => $type->headingRanks($field), 'data-fb-heading-flow' => json_encode($headings->flow(), \JSON_THROW_ON_ERROR)];
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
     * One part of a field, marked like the field itself plus its name in
     * the editing mode, so a click there opens the same editor.
     *
     * @param array<string, int|string> $extra
     */
    private function part(string $name, Field $field, FieldType $type, mixed $value, string $part, array $extra): Markup|string
    {
        if (!$type instanceof HasParts || !\in_array($part, $type->parts($field), true)) {
            $parts = $type instanceof HasParts ? $type->parts($field) : [];

            throw new ViewException(\sprintf(
                'The field "%s" has no part "%s". Parts: %s.',
                $name,
                $part,
                [] === $parts ? 'none' : implode(', ', $parts) . ('caption' !== $part || \in_array('caption', $parts, true) ? '' : ' (a caption needs "caption: true" in the field)'),
            ));
        }

        $output = $type->part($value, $field, $part);

        // For visitors an empty part leaves out its element (decision 0144); while editing it shows its place.
        if (null === $this->markup || $this->markup->anchors) {
            return self::isEmpty($output) ? EmptyElements::mark() : $output;
        }

        return $this->markup->field($this->path . $name, $field, $output, $value, [...$extra, 'data-fb-part' => $part]);
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
     * A heading from data instead of a field, e.g. the title of a news
     * article in a list (decision 0219). The level comes from where it
     * stands, as with heading(); empty text gives nothing. With an
     * address the text is a link inside the heading, e.g. to the article.
     *
     * @param array<mixed> $attributes
     */
    public function headingText(string $text, int $rank = 0, array $attributes = [], ?string $href = null): Markup
    {
        $headings = $this->headings ?? throw new ViewException('heading_text() works only in blocks and layouts; write a fixed level here.');

        if ('' === trim($text)) {
            return EmptyElements::mark();
        }

        $tag = 'h' . $headings->take($rank, Html::escape($text), '');

        $content = null === $href || '' === $href ? Html::escape($text) : Html::element('a', Html::escape($text), ['href' => $href]);

        return new Markup(Html::open($tag, self::attributes($attributes, 'heading_text()')) . $content . Html::close($tag), 'UTF-8');
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
    public static function attributes(array $attributes, string $function = 'heading()'): array
    {
        $checked = [];

        foreach ($attributes as $name => $value) {
            if (!\is_string($name) || 1 !== preg_match(self::ATTRIBUTE, $name)) {
                throw new ViewException(\sprintf('%s got "%s" as the name of an attribute.', $function, (string) $name));
            }

            if (null !== $value && !\is_scalar($value)) {
                throw new ViewException(\sprintf('%s got no text for the attribute "%s".', $function, $name));
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
