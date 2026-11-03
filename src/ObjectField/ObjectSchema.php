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
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * The further fields of each type of objects (decisions 0234, 0240),
 * gathered from "object.fields" in the views of the active theme and from
 * extensions. The first source of a name counts; the same name with
 * another type is a problem, which blocks:check lists and development
 * stops at.
 *
 * Some types have further fields per space, e.g. those of a collection
 * from the backend (decision 0250). They come after those of the views
 * and leave out their names.
 */
final class ObjectSchema
{
    /** @var array<string, array<string, ObjectField>>|null */
    private ?array $types = null;

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, array<string, ObjectField>> Fields of a space by "type space" */
    private array $spaces = [];

    /** @var array<string, string> The space of an object by "type:id" */
    private array $objects = [];

    public function __construct(
        private readonly Components $components,
        private readonly Plugins $plugins,
        private readonly Registry $registry,
        private readonly DefinitionReader $reader,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @param string|null $space With the fields of this space
     *
     * @return array<string, ObjectField> By name, in the order found
     */
    public function fields(string $type, ?string $space = null): array
    {
        $fields = $this->all()[$type] ?? [];

        return null === $space ? $fields : $fields + $this->spaceFields($type, $space);
    }

    public function field(string $type, string $name, ?string $space = null): ?ObjectField
    {
        return $this->all()[$type][$name] ?? (null === $space ? null : $this->spaceFields($type, $space)[$name] ?? null);
    }

    /**
     * The fields of a space alone (decision 0250), without names the
     * views or extensions declare.
     *
     * @return array<string, ObjectField> By name, in their order
     */
    public function spaceFields(string $type, string $space): array
    {
        $key = $type . ' ' . $space;

        if (isset($this->spaces[$key])) {
            return $this->spaces[$key];
        }

        $known = $this->all()[$type] ?? [];
        $fields = [];

        foreach ($this->registry->items(SpaceFields::class) as $item) {
            if ($item->type !== $type) {
                continue;
            }

            $source = $this->container->get($item->source);

            if (!$source instanceof SpaceFieldSource) {
                throw new LogicException(\sprintf('%s is no %s.', $item->source, SpaceFieldSource::class));
            }

            $name = $source->name($space);
            // The module checks the fields it stores; a broken one is left out.
            $read = $this->reader->objectFields($source->fields($space), $name, new DefinitionErrors());

            foreach ($read as $field => $definition) {
                if (!isset($known[$field]) && !isset($fields[$field])) {
                    $fields[$field] = ObjectField::of($definition, $name);
                }
            }
        }

        return $this->spaces[$key] = $fields;
    }

    /**
     * Whether objects of a type have fields per space.
     */
    public function bySpace(string $type): bool
    {
        foreach ($this->registry->items(SpaceFields::class) as $item) {
            if ($item->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * The space whose fields an object has, that of its draft first; null
     * for types without fields per space.
     */
    public function spaceOf(string $type, string $id): ?string
    {
        if (!$this->bySpace($type)) {
            return null;
        }

        $key = $type . ':' . $id;

        if (isset($this->objects[$key])) {
            return $this->objects[$key];
        }

        $space = $this->plugins->get($type)?->space($id);

        // An object not found yet may be created later in the request.
        if (null !== $space) {
            $this->objects[$key] = $space;
        }

        return $space;
    }

    /**
     * Forgets what was read, e.g. after the fields of a space changed.
     */
    public function reset(): void
    {
        $this->spaces = [];
        $this->objects = [];
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
