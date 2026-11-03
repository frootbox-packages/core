<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\HasParts;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Plugin\Plugins;
use Frootbox\Core\View\EditMarkup;
use Frootbox\Core\View\EmptyElements;
use Twig\Error\RuntimeError;
use Twig\Markup;

/**
 * The further fields of an object for templates (decisions 0234, 0240):
 * object_field() as the field type shows it, object_value() as stored,
 * e.g. a switch. A name no view or extension declares is an error in
 * development and nothing live.
 *
 * While drafts are shown, the values come from the draft of the object;
 * with marking, object_field() marks the field for editing if the user
 * may change the object (decision 0241).
 *
 * Fields of the object's space, e.g. from the backend of a collection,
 * count too; object_facts() lists them as a fact sheet (decision 0250).
 */
final class ObjectOutput
{
    /** Types not edited on the website: a switch has nothing to click, lists come later, numbers, days and choices in the backend. */
    private const array NOT_MARKED = ['boolean', 'list', 'number', 'date', 'choice'];

    /** @var array<string, bool> Whether the user may change objects of a type */
    private array $editable = [];

    /** @var array<string, array<string, true>> Names shown with object_field() by "type:id", left out of the fact sheet */
    private array $shown = [];

    public function __construct(
        private readonly ObjectSchema $schema,
        private readonly ObjectFields $fields,
        private readonly FieldTypes $types,
        private readonly Environment $environment,
        private readonly EditMode $editMode,
        private readonly EditMarkup $markup,
        private readonly MarkedObjects $marked,
        private readonly Catalog $catalog,
        private readonly Gate $gate,
        private readonly CurrentContext $context,
        private readonly Plugins $plugins,
    ) {}

    /**
     * @param list<string> $languages The language and its fallbacks
     */
    public function field(string $type, string $id, string $name, array $languages): mixed
    {
        $field = $this->declared($type, $id, $name, 'object_field');

        if (null === $field) {
            return '';
        }

        $this->shown[$type . ':' . $id][$name] = true;

        $value = ObjectFields::valueIn($field, $this->values($type, $id), $name, $languages);
        $kind = $this->types->get($field->field->type);
        $output = $kind->output($value, $field->field);

        if (!$this->editMode->marking() || \in_array($field->field->type, self::NOT_MARKED, true) || !$this->editable($type) || !(\is_string($output) || $output instanceof Markup)) {
            // As with the fields of pages, an empty one leaves out its element (decision 0144).
            return '' === $output || ($output instanceof Markup && '' === (string) $output) ? EmptyElements::mark() : $output;
        }

        if (!$this->marked->has($type, $id)) {
            $plugin = $this->plugins->get($type);
            $title = '';

            foreach ($languages as $language) {
                $title = $plugin?->title($id, $language) ?? '';

                if ('' !== $title) {
                    break;
                }
            }

            $this->marked->add($type, $id, $plugin?->label() ?? $type, $title, null !== $this->fields->draft($type, $id));
        }

        return $this->markup->field($name, $field->field, $output, $value, [
            'data-fb-object' => $type . ':' . $id,
            // Where the value goes: the language the content is shown in.
            'data-fb-language' => $languages[0] ?? '',
            // Further fields may stay empty (decision 0240).
            ...($field->field->optional ? [] : ['data-fb-optional' => '']),
            ...($kind instanceof HasParts ? ['data-fb-parts' => implode(' ', $kind->parts($field->field))] : []),
        ]);
    }

    /**
     * @param list<string> $languages The language and its fallbacks
     */
    public function value(string $type, string $id, string $name, array $languages): mixed
    {
        $field = $this->declared($type, $id, $name, 'object_value');

        if (null === $field) {
            return null;
        }

        $value = ObjectFields::valueIn($field, $this->values($type, $id), $name, $languages);

        return 'boolean' === $field->field->type ? true === $value : $value;
    }

    /**
     * The fields of the object's space with a value, in their order, for
     * a fact sheet ("Label: value"). Fields the view showed before with
     * object_field() or names in $except are left out.
     *
     * @param list<string> $languages The language and its fallbacks
     * @param list<string> $except
     *
     * @return list<array{name: string, type: string, label: string, value: mixed, output: mixed}>
     */
    public function facts(string $type, string $id, array $languages, array $except = []): array
    {
        $space = $this->schema->spaceOf($type, $id);

        if (null === $space) {
            return [];
        }

        $values = $this->values($type, $id);
        $facts = [];

        foreach ($this->schema->spaceFields($type, $space) as $name => $field) {
            if (isset($this->shown[$type . ':' . $id][$name]) || \in_array($name, $except, true)) {
                continue;
            }

            $value = ObjectFields::valueIn($field, $values, $name, $languages);
            $output = $this->types->get($field->field->type)->output($value, $field->field);

            // A switch is a fact either way once it was set.
            if (null === $value || ('' === $output && 'boolean' !== $field->field->type) || ($output instanceof Markup && '' === (string) $output)) {
                continue;
            }

            $facts[] = ['name' => $name, 'type' => $field->field->type, 'label' => $field->label($languages), 'value' => $value, 'output' => $output];
        }

        return $facts;
    }

    /**
     * @return array<mixed>
     */
    private function values(string $type, string $id): array
    {
        return $this->editMode->active() ? $this->fields->current($type, $id) : $this->fields->of($type, [$id])[$id] ?? [];
    }

    /**
     * Whether the user may run the action of the module that changes its objects.
     */
    private function editable(string $type): bool
    {
        $action = $type . '.update';

        return $this->editable[$type] ??= $this->catalog->has($action) && $this->gate->allows($this->context->get()->actor, $this->catalog->get($action));
    }

    private function declared(string $type, string $id, string $name, string $function): ?ObjectField
    {
        if ($this->environment->isDevelopment() && [] !== $this->schema->problems()) {
            throw new RuntimeError(\sprintf('%s(): the further fields of objects have problems: %s', $function, implode(' ', $this->schema->problems())));
        }

        $field = $this->schema->field($type, $name);

        if (null !== $field) {
            return $field;
        }

        // A field of the space may be missing in another space, so only views must declare theirs.
        $space = $this->schema->spaceOf($type, $id);
        $field = null === $space ? null : $this->schema->field($type, $name, $space);

        if (null === $field && $this->environment->isDevelopment() && !$this->schema->bySpace($type)) {
            $known = array_keys($this->schema->fields($type));

            throw new RuntimeError(\sprintf('%s(): %s has no field "%s". Declare it under "object: fields:" in the header of the view. Fields: %s.', $function, $type, $name, [] === $known ? 'none' : implode(', ', $known)));
        }

        return $field;
    }
}
