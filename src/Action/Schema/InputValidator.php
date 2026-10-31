<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use Frootbox\Core\Support\Message;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * Checks input against a JSON schema (opis/json-schema). The messages are
 * our own templates (decision 0115): short, translatable and at the field
 * they belong to, e.g. "/email": "is required."
 */
final class InputValidator
{
    private readonly Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator(max_errors: 20, stop_at_first_error: false);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $input
     *
     * @return array<string, string> Message by JSON pointer, empty if the input is valid
     */
    public function errors(array $schema, array $input): array
    {
        return array_map(static fn(Message $message): string => (string) $message, $this->messages($schema, $input));
    }

    /**
     * Like errors(), as templates with values for translation.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $input
     *
     * @return array<string, Message> by JSON pointer, empty if the input is valid
     */
    public function messages(array $schema, array $input): array
    {
        $unknown = $this->unknownFields($schema, $input);

        // opis also reports known fields as "additional" when they are invalid,
        // so unknown fields are checked here instead.
        unset($schema['additionalProperties']);

        $data = self::objects(Helper::toJSON($input), $schema);
        $schemaObject = Helper::toJSON($schema);

        if (!\is_object($schemaObject)) {
            throw new \LogicException('A JSON schema must be an object.');
        }

        $error = $this->validator->validate($data, $schemaObject)->error();
        $messages = [];

        if (null !== $error) {
            self::collect($error, $messages);
        }

        if ([] !== $unknown) {
            $messages['/'] = new Message('Unknown fields: {fields}', ['fields' => implode(', ', $unknown)]);
        }

        return $messages;
    }

    /**
     * PHP has one empty array for [] and {}, e.g. for an entry of a list
     * without values read from JSON. Where the schema expects an object,
     * an empty array is one.
     *
     * @param array<mixed> $schema
     */
    private static function objects(mixed $data, array $schema): mixed
    {
        $type = $schema['type'] ?? null;
        $object = 'object' === $type || (\is_array($type) && \in_array('object', $type, true) && !\in_array('array', $type, true));

        if ([] === $data) {
            return $object ? new stdClass() : $data;
        }

        if ($data instanceof stdClass && \is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $name => $property) {
                if (\is_string($name) && property_exists($data, $name) && \is_array($property)) {
                    $data->{$name} = self::objects($data->{$name}, $property);
                }
            }
        }

        if (\is_array($data) && \is_array($schema['items'] ?? null)) {
            $items = $schema['items'];
            $data = array_map(static fn(mixed $item): mixed => self::objects($item, $items), $data);
        }

        return $data;
    }

    /**
     * The innermost errors, the first one per field.
     *
     * @param array<string, Message> $messages
     */
    private static function collect(ValidationError $error, array &$messages): void
    {
        if ([] !== $error->subErrors()) {
            foreach ($error->subErrors() as $sub) {
                if ($sub instanceof ValidationError) {
                    self::collect($sub, $messages);
                }
            }

            return;
        }

        $path = $error->data()->fullPath();
        $args = $error->args();
        $missing = $args['missing'] ?? null;

        if ('required' === $error->keyword() && \is_array($missing)) {
            foreach ($missing as $field) {
                $messages[self::pointer([...$path, \is_scalar($field) ? (string) $field : ''])] ??= new Message('is required.');
            }

            return;
        }

        $messages[self::pointer($path)] ??= self::message($error, $args);
    }

    /**
     * @param array<mixed> $args
     */
    private static function message(ValidationError $error, array $args): Message
    {
        $number = static fn(string $key): int|float|string => \is_int($args[$key] ?? null) || \is_float($args[$key] ?? null) ? $args[$key] : '?';
        $format = $args['format'] ?? null;

        // An empty text where one is needed, e.g. an empty field of a form.
        if ('' === $error->data()->value() && \in_array($error->keyword(), ['minLength', 'format', 'pattern'], true)) {
            return new Message('is required.');
        }

        return match ($error->keyword()) {
            'minLength' => new Message('must be at least {min} characters long.', ['min' => $number('min')]),
            'maxLength' => new Message('must be at most {max} characters long.', ['max' => $number('max')]),
            'minimum' => new Message('must be at least {min}.', ['min' => $number('min')]),
            'maximum' => new Message('must be at most {max}.', ['max' => $number('max')]),
            'exclusiveMinimum' => new Message('must be more than {min}.', ['min' => $number('min')]),
            'exclusiveMaximum' => new Message('must be less than {max}.', ['max' => $number('max')]),
            'minItems' => new Message('needs at least {min} entries.', ['min' => $number('min')]),
            'maxItems' => new Message('can have at most {max} entries.', ['max' => $number('max')]),
            'pattern' => new Message('has the wrong format.'),
            'enum', 'const' => new Message('is not one of the allowed values.'),
            'format' => 'email' === $format
                ? new Message('must be an email address.')
                : new Message('must be a valid {format}.', ['format' => \is_string($format) ? $format : 'value']),
            'type' => self::typeMessage($args['expected'] ?? null),
            default => new Message(self::fill($error->message(), $args)),
        };
    }

    private static function typeMessage(mixed $expected): Message
    {
        $types = array_values(array_filter(\is_array($expected) ? $expected : [$expected], static fn(mixed $type): bool => 'null' !== $type));

        return match ($types[0] ?? null) {
            'string' => new Message('must be text.'),
            'integer' => new Message('must be a whole number.'),
            'number' => new Message('must be a number.'),
            'boolean' => new Message('must be true or false.'),
            'array' => new Message('must be a list.'),
            'object' => new Message('must be an object.'),
            default => new Message('has the wrong type.'),
        };
    }

    /**
     * The message of opis for keywords without a template of ours.
     *
     * @param array<mixed> $args
     */
    private static function fill(string $text, array $args): string
    {
        $replace = [];

        foreach ($args as $name => $value) {
            $replace['{' . $name . '}'] = \is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return '' === $text ? 'is invalid.' : strtr($text, $replace);
    }

    /**
     * @param array<int|string> $path
     */
    private static function pointer(array $path): string
    {
        if ([] === $path) {
            return '/';
        }

        return '/' . implode('/', array_map(static fn(int|string $part): string => strtr((string) $part, ['~' => '~0', '/' => '~1']), $path));
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    private function unknownFields(array $schema, array $input): array
    {
        if (false !== ($schema['additionalProperties'] ?? true)) {
            return [];
        }

        $properties = $schema['properties'] ?? [];

        return array_values(array_diff(array_keys($input), \is_array($properties) ? array_keys($properties) : []));
    }
}
