<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\I18n;

use Frootbox\Core\Component\Area;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Option;
use Frootbox\Core\Component\Variant;
use Frootbox\Core\Field\Field;

/**
 * Labels from manifests and schemas in the language of the backend
 * (decision 0161): titles, descriptions, labels and placeholders of
 * blocks, layouts and their fields, areas, variants and options. The
 * translation of the package a label comes from wins.
 */
final class Labels
{
    /** The package of names without a slash, e.g. the field type "headline". */
    public const string CORE = 'frootbox/core';

    /** @var array<string, Component> By language, kind and name */
    private array $components = [];

    public function __construct(private readonly Translator $translator) {}

    /**
     * @param string|list<string>|null $package One or several, the first that has it wins
     */
    public function text(string $text, string|array|null $package = null): string
    {
        return $this->translator->label($text, $package);
    }

    /**
     * The component with its texts translated.
     */
    public function component(Component $component): Component
    {
        $key = $this->translator->language() . ':' . $component->kind->value . ':' . $component->name;

        if (isset($this->components[$key])) {
            return $this->components[$key];
        }

        $package = self::packageOf($component->name);

        return $this->components[$key] = new Component(
            $component->kind,
            $component->name,
            $component->path,
            $this->text($component->title, $package),
            $this->text($component->description, $package),
            $this->fields($component->fields, $package),
            array_map(fn(Area $area): Area => new Area($area->name, $this->text($area->label, $package), $this->text($area->description, $package), $area->allow, $area->min, $area->max, $area->level), $component->areas),
            array_map(fn(Variant $variant): Variant => $this->variant($variant, $package), $component->variants),
            $component->assets,
            $component->widget,
            $component->group,
            array_map(fn(string $keyword): string => $this->text($keyword, $package), $component->keywords),
            $component->previews,
            $component->tailored,
            $component->examples,
            $component->query,
            $component->controller,
            $component->controllerFile,
            $component->plugin,
        );
    }

    /**
     * @param array<string, Field> $fields
     *
     * @return array<string, Field>
     */
    public function fields(array $fields, ?string $package = null): array
    {
        return array_map(fn(Field $field): Field => new Field(
            $field->name,
            $field->type,
            $this->text($field->label, $package),
            $this->text($field->description, $package),
            $field->optional,
            $field->settings,
            $this->fields($field->fields, $package),
            $this->text($field->placeholder, $package),
        ), $fields);
    }

    /**
     * The package of a block, layout or field type by its name:
     * "acme/website" for "acme/website/Teaser" or the group
     * "acme/website/teasers", the core without a package.
     */
    public static function packageOf(string $name): string
    {
        $parts = explode('/', $name);

        return \count($parts) >= 3 ? $parts[0] . '/' . $parts[1] : self::CORE;
    }

    private function variant(Variant $variant, string $package): Variant
    {
        $options = array_map(fn(Option $option): Option => new Option(
            $option->name,
            $option->type,
            $this->text($option->label, $package),
            $option->default,
            $this->text($option->description, $package),
            array_map(fn(string $label): string => $this->text($label, $package), $option->choices),
            $option->min,
            $option->max,
            $option->source,
        ), $variant->options);

        return new Variant($variant->name, $this->text($variant->title, $package), $this->text($variant->description, $package), $options, $variant->template, $variant->from);
    }
}
