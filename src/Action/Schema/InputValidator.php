<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * Checks input against a JSON schema (opis/json-schema).
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
        $unknown = $this->unknownFields($schema, $input);

        // opis also reports known fields as "additional" when they are invalid,
        // so unknown fields are checked here instead.
        unset($schema['additionalProperties']);

        $data = [] === $input ? new stdClass() : Helper::toJSON($input);
        $schemaObject = Helper::toJSON($schema);

        if (!\is_object($schemaObject)) {
            throw new \LogicException('A JSON schema must be an object.');
        }

        $result = $this->validator->validate($data, $schemaObject);
        $error = $result->error();

        $errors = [];

        if (null !== $error) {
            foreach (new ErrorFormatter()->format($error, false) as $pointer => $message) {
                $errors[(string) $pointer] = \is_string($message) ? $message : 'is invalid';
            }
        }

        if ([] !== $unknown) {
            $message = \sprintf('Unknown fields: %s', implode(', ', $unknown));
            $errors['/'] = isset($errors['/']) ? $errors['/'] . '; ' . $message : $message;
        }

        return $errors;
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
