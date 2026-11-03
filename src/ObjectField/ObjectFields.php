<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Field\Blank;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Support\Message;

/**
 * The further fields at objects of modules (decisions 0234, 0240). A
 * module keeps them in the draft: check() turns the input of an action
 * into what it stores. It passes them on when it publishes (sync()) or
 * takes an object offline (clear()); the website reads them with of().
 *
 * Input and draft hold a value by name, a field per language
 * {"de": …, "en": …}. Null or an empty value removes it; names left out
 * stay as they are, also those no view knows any more.
 */
final class ObjectFields
{
    /** @var array<string, array<mixed>> Published values by target, read once per request */
    private array $published = [];

    public function __construct(
        private readonly ObjectSchema $schema,
        private readonly ObjectValues $values,
        private readonly FieldTypes $types,
        private readonly Languages $languages,
        private readonly InputValidator $validator,
    ) {}

    /**
     * The values of the draft after an action's input. Drafts may be
     * unfinished, so minimums count only on publishing.
     *
     * @param array<mixed> $current The values of the draft so far
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws InvalidInputException
     */
    public function check(string $action, string $type, array $current, array $input, string $pointer = '/fields'): array
    {
        $fields = $this->schema->fields($type);
        $result = [];
        $errors = [];

        foreach ($current as $name => $value) {
            $result[(string) $name] = $value;
        }

        if ([] !== $input && array_is_list($input)) {
            throw new InvalidInputException($action, [$pointer => 'must be an object with a value by name of the field, see object.fields.']);
        }

        foreach ($input as $name => $value) {
            $name = (string) $name;
            $at = $pointer . '/' . $name;
            $field = $fields[$name] ?? null;

            if (null === $field) {
                // Values no view knows any more may still be removed.
                if (null === $value) {
                    unset($result[$name]);
                } else {
                    $errors[$at] = new Message('{type} has no field "{name}". Fields: {fields}.', ['type' => $type, 'name' => $name, 'fields' => [] === $fields ? 'none' : implode(', ', array_keys($fields))]);
                }

                continue;
            }

            if (!$field->translatable) {
                if (Blank::is($value)) {
                    unset($result[$name]);
                } else {
                    $errors += $this->errors($field, $value, $at);
                    $result[$name] = $value;
                }

                continue;
            }

            if (null === $value) {
                unset($result[$name]);

                continue;
            }

            if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
                $errors[$at] = new Message('must be an object with a value by language, e.g. {"{language}": …}.', ['language' => $this->languages->default()->code]);

                continue;
            }

            $texts = \is_array($result[$name] ?? null) ? $result[$name] : [];

            foreach ($value as $language => $text) {
                $language = (string) $language;

                if (!$this->languages->has($language)) {
                    $errors[$at . '/' . $language] = new Message('There is no language "{language}". Languages: {languages}.', ['language' => $language, 'languages' => implode(', ', $this->languages->codes())]);

                    continue;
                }

                if (Blank::is($text) || self::emptyDocument($text)) {
                    unset($texts[$language]);
                } else {
                    $errors += $this->errors($field, $text, $at . '/' . $language);
                    $texts[$language] = $text;
                }
            }

            if ([] === $texts) {
                unset($result[$name]);
            } else {
                $result[$name] = $texts;
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $result;
    }

    /**
     * Sets the values of a published object.
     *
     * @param array<mixed> $values
     */
    public function sync(string $type, string $id, array $values): void
    {
        $row = $this->values->fetchOne(['where' => ['targetType' => $type, 'targetId' => $id]]);
        unset($this->published[$type . ':' . $id]);

        if ([] === $values) {
            if (null !== $row) {
                $this->values->delete($row->id);
            }

            return;
        }

        if (null === $row) {
            $this->values->insert(['targetType' => $type, 'targetId' => $id, 'fields' => $values]);
        } elseif ($row->fields !== $values) {
            $this->values->update($row->id, ['fields' => $values]);
        }
    }

    public function clear(string $type, string $id): void
    {
        $this->sync($type, $id, []);
    }

    /**
     * The values of published objects, by object.
     *
     * @param list<string> $ids
     *
     * @return array<string, array<mixed>>
     */
    public function of(string $type, array $ids): array
    {
        $result = [];
        $missing = [];

        foreach ($ids as $id) {
            if (isset($this->published[$type . ':' . $id])) {
                $result[$id] = $this->published[$type . ':' . $id];
            } else {
                $missing[] = $id;
            }
        }

        if ([] !== $missing) {
            foreach ($missing as $id) {
                $result[$id] = $this->published[$type . ':' . $id] = [];
            }

            foreach ($this->values->fetch(['where' => ['targetType' => $type, 'targetId' => $missing]]) as $row) {
                $result[$row->targetId] = $this->published[$type . ':' . $row->targetId] = $row->fields;
            }
        }

        return $result;
    }

    /**
     * The value of a field in the first of the languages that has one;
     * the same for all languages if the field holds one value.
     *
     * @param array<mixed> $values
     * @param list<string> $languages The language and its fallbacks
     */
    public static function valueIn(ObjectField $field, array $values, string $name, array $languages): mixed
    {
        $value = $values[$name] ?? null;

        if (!$field->translatable) {
            return $value;
        }

        foreach ($languages as $language) {
            if (\is_array($value) && null !== ($value[$language] ?? null)) {
                return $value[$language];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function errors(ObjectField $field, mixed $value, string $at): array
    {
        $type = $this->types->get($field->field->type);
        $schema = ['type' => 'object', 'properties' => ['value' => ContentValidator::withoutMinimums($type->valueSchema($field->field))]];
        $errors = [];

        foreach ($this->validator->errors($schema, ['value' => $value]) as $path => $message) {
            $errors[$at . substr($path, \strlen('/value'))] = $message;
        }

        if ([] === $errors) {
            foreach ($type->validate($value, $field->field) as $path => $message) {
                $errors[$at . $path] = $message;
            }
        }

        return $errors;
    }

    /**
     * Rich text with nothing in it, as an empty editor sends it.
     */
    private static function emptyDocument(mixed $value): bool
    {
        return \is_array($value) && 'doc' === ($value['type'] ?? null) && Blank::is($value['content'] ?? null);
    }
}
