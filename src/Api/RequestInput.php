<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Api;

use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Http\Exception\HttpException;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the input of an action or query from a request: the query string
 * for queries, a JSON object or form data for actions.
 *
 * Query strings and forms only know strings, so their values are
 * converted along the schema: "5" to 5, "true" to true, lists as ids[]=1.
 * JSON keeps its types.
 */
final class RequestInput
{
    /**
     * @return array<string, mixed>
     */
    public static function of(ServerRequestInterface $request, Descriptor $descriptor): array
    {
        if (!$descriptor->isAction()) {
            return self::coerce($descriptor->schema(), self::keyed($request->getQueryParams()));
        }

        $body = (string) $request->getBody();

        if ('' === trim($body)) {
            return [];
        }

        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        if ('application/json' === $type || str_ends_with($type, '+json')) {
            return self::json($body);
        }

        if ('application/x-www-form-urlencoded' === $type) {
            parse_str($body, $form);

            return self::coerce($descriptor->schema(), self::keyed($form));
        }

        throw new HttpException(415, 'Send the input as application/json or application/x-www-form-urlencoded.');
    }

    /**
     * Converts strings to the types the schema expects. Values that do not
     * fit stay as they are, so the validator can name the error.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function coerce(array $schema, array $input): array
    {
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($input as $name => $value) {
            $property = $properties[$name] ?? null;

            if (\is_array($property)) {
                $input[$name] = self::value(self::keyed($property), $value);
            }
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $body): array
    {
        try {
            $data = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'The body is not valid JSON.');
        }

        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw new HttpException(400, 'The body must be a JSON object.');
        }

        return self::keyed($data);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function value(array $schema, mixed $value): mixed
    {
        if (\is_array($value) && array_is_list($value) && \is_array($schema['items'] ?? null)) {
            $items = self::keyed($schema['items']);

            return array_map(static fn(mixed $item): mixed => self::value($items, $item), $value);
        }

        if (!\is_string($value)) {
            return $value;
        }

        if (\is_array($schema['enum'] ?? null)) {
            foreach ($schema['enum'] as $case) {
                if (\is_int($case) && (string) $case === $value) {
                    return $case;
                }
            }

            return $value;
        }

        $types = (array) ($schema['type'] ?? []);

        return match (true) {
            \in_array('string', $types, true) => $value,
            \in_array('integer', $types, true) && 1 === preg_match('/^-?\d+$/', $value) => (int) $value,
            \in_array('number', $types, true) && is_numeric($value) => (float) $value,
            \in_array('boolean', $types, true) && \in_array($value, ['true', '1'], true) => true,
            \in_array('boolean', $types, true) && \in_array($value, ['false', '0'], true) => false,
            \in_array('null', $types, true) && '' === $value => null,
            default => $value,
        };
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
