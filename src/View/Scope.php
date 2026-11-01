<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Field\Blank;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Twig\Markup;

/**
 * The fields of a block, a layout or one list entry with their values,
 * as seen by field() and value() in a template.
 */
final readonly class Scope
{
    /**
     * @param array<string, Field> $fields
     * @param array<mixed>         $values
     * @param EditMarkup|null      $markup In the editing mode, for users who may change the page (decision 0119)
     * @param string               $path   Where these fields are inside the block, e.g. "slides/0/"
     */
    public function __construct(
        private array $fields,
        private array $values,
        private FieldTypes $types,
        private ?EditMarkup $markup = null,
        private string $path = '',
    ) {}

    /**
     * The same fields with the markup of the editing mode at a place.
     */
    public function within(EditMarkup $markup, string $path): self
    {
        return new self($this->fields, $this->values, $this->types, $markup, $path);
    }

    /**
     * The field ready for output, e.g. {{ slide.field('title') }}.
     */
    public function field(string $name): mixed
    {
        $field = $this->definition($name);
        $output = $this->types->get($field->type)->output($this->values[$name] ?? null, $field);

        // A preview shows what visitors see, with light marks (decision 0156).
        $visitor = null === $this->markup || $this->markup->anchors;

        return match (true) {
            // For visitors an empty field leaves out its element, a list its blank entries (decision 0144).
            null === $this->markup && $output instanceof ListValue => $output->withoutBlank(),
            // The paths first, so they keep the stored positions.
            $visitor && $output instanceof ListValue => $output->within($this->markup, $this->path . $name . '/')->withoutBlank(),
            $visitor && ('' === $output || ($output instanceof Markup && '' === (string) $output)) => EmptyElements::mark(),
            null === $this->markup => $output,
            $output instanceof ListValue => $output->within($this->markup, $this->path . $name . '/'),
            \is_string($output) || $output instanceof Markup => $this->markup->field($this->path . $name, $field, $output, $this->values[$name] ?? null),
            default => $output,
        };
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

    private function definition(string $name): Field
    {
        return $this->fields[$name] ?? throw new ViewException(\sprintf(
            'There is no field "%s". Fields: %s.',
            $name,
            [] === $this->fields ? 'none' : implode(', ', array_keys($this->fields)),
        ));
    }
}
