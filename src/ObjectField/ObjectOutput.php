<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Core\Config\Environment;
use Frootbox\Core\Field\FieldTypes;
use Twig\Error\RuntimeError;

/**
 * The further fields of a published object for templates (decisions
 * 0234, 0240): object_field() as the field type shows it, object_value()
 * as stored, e.g. a switch. A name no view or extension declares is an
 * error in development and nothing live.
 */
final readonly class ObjectOutput
{
    public function __construct(
        private ObjectSchema $schema,
        private ObjectFields $fields,
        private FieldTypes $types,
        private Environment $environment,
    ) {}

    /**
     * @param list<string> $languages The language and its fallbacks
     */
    public function field(string $type, string $id, string $name, array $languages): mixed
    {
        $field = $this->declared($type, $name, 'object_field');

        if (null === $field) {
            return '';
        }

        $value = ObjectFields::valueIn($field, $this->fields->of($type, [$id])[$id] ?? [], $name, $languages);

        return $this->types->get($field->field->type)->output($value, $field->field);
    }

    /**
     * @param list<string> $languages The language and its fallbacks
     */
    public function value(string $type, string $id, string $name, array $languages): mixed
    {
        $field = $this->declared($type, $name, 'object_value');

        if (null === $field) {
            return null;
        }

        $value = ObjectFields::valueIn($field, $this->fields->of($type, [$id])[$id] ?? [], $name, $languages);

        return 'boolean' === $field->field->type ? true === $value : $value;
    }

    private function declared(string $type, string $name, string $function): ?ObjectField
    {
        if ($this->environment->isDevelopment() && [] !== $this->schema->problems()) {
            throw new RuntimeError(\sprintf('%s(): the further fields of objects have problems: %s', $function, implode(' ', $this->schema->problems())));
        }

        $field = $this->schema->field($type, $name);

        if (null === $field && $this->environment->isDevelopment()) {
            $known = array_keys($this->schema->fields($type));

            throw new RuntimeError(\sprintf('%s(): %s has no field "%s". Declare it under "object: fields:" in the header of the view. Fields: %s.', $function, $type, $name, [] === $known ? 'none' : implode(', ', $known)));
        }

        return $field;
    }
}
