<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\DefinitionErrors;
use Frootbox\Core\Component\DefinitionReader;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Plugin\Plugins;

/**
 * The further fields of each type of objects (decisions 0234, 0240),
 * gathered from "object.fields" in the views of the active theme and from
 * extensions. The first source of a name counts; the same name with
 * another type is a problem, which blocks:check lists and development
 * stops at.
 */
final class ObjectSchema
{
    /** @var array<string, array<string, ObjectField>>|null */
    private ?array $types = null;

    /** @var list<string> */
    private array $problems = [];

    public function __construct(
        private readonly Components $components,
        private readonly Plugins $plugins,
        private readonly Registry $registry,
        private readonly DefinitionReader $reader,
    ) {}

    /**
     * @return array<string, ObjectField> By name, in the order found
     */
    public function fields(string $type): array
    {
        return $this->all()[$type] ?? [];
    }

    public function field(string $type, string $name): ?ObjectField
    {
        return $this->all()[$type][$name] ?? null;
    }

    /**
     * Types with further fields.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->all());
    }

    /**
     * Names with two types, views without a type, broken definitions of
     * extensions.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $this->all();

        return $this->problems;
    }

    /**
     * @return array<string, array<string, ObjectField>>
     */
    private function all(): array
    {
        if (null !== $this->types) {
            return $this->types;
        }

        $types = [];
        $this->problems = [];

        foreach ([...$this->components->blocks, ...$this->components->layouts] as $component) {
            foreach ($component->variants as $variant) {
                if (null === $variant->object) {
                    continue;
                }

                $source = \sprintf('%s, view %s', $component->name, $variant->name) . ($variant->from === $component->package() ? '' : \sprintf(' (%s)', $variant->from));
                $type = $variant->object->type ?? ($component->plugin ? $this->plugins->ofBlock($component->name)?->type() : null);

                if (null === $type) {
                    $this->problems[] = \sprintf('%s: object.type is missing. Name the type of the objects the view shows, e.g. "type: frootbox/news/article".', $source);

                    continue;
                }

                foreach ($variant->object->fields as $name => $field) {
                    $this->add($types, $type, $name, ObjectField::of($field, $source));
                }
            }
        }

        foreach ($this->registry->items(ObjectFieldSet::class) as $set) {
            $errors = new DefinitionErrors();
            $fields = $this->reader->objectFields($set->fields, $set->from, $errors);

            foreach ($errors->all() as $problem) {
                $this->problems[] = $problem;
            }

            foreach ($fields as $name => $field) {
                $this->add($types, $set->type, $name, ObjectField::of($field, $set->from));
            }
        }

        return $this->types = $types;
    }

    /**
     * @param array<string, array<string, ObjectField>> $types
     */
    private function add(array &$types, string $type, string $name, ObjectField $field): void
    {
        $known = $types[$type][$name] ?? null;

        if (null === $known) {
            $types[$type][$name] = $field;

            return;
        }

        if ($known->field->type !== $field->field->type || $known->translatable !== $field->translatable) {
            $this->problems[] = \sprintf('%s: the field "%s" of %s is a %s%s there, but a %s%s in %s. A name has one type for all views.', $field->sources[0], $name, $type, $field->field->type, $field->translatable ? ' per language' : '', $known->field->type, $known->translatable ? ' per language' : '', implode(', ', $known->sources));

            return;
        }

        $types[$type][$name] = $known->from($field->sources[0]);
    }
}
