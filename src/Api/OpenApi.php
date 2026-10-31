<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Api;

use Frootbox\Core\Action\ActionName;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Gate;
use stdClass;

/**
 * Describes the REST API as OpenAPI 3.1, built from the catalog and the
 * input schemas. It lists only what the actor may run.
 */
final readonly class OpenApi
{
    public const string VERSION = '3.1.0';

    public function __construct(
        private Catalog $catalog,
        private Gate $gate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function document(Actor $actor): array
    {
        $paths = [];

        foreach ($this->catalog->all() as $descriptor) {
            if ($this->gate->allows($actor, $descriptor)) {
                $paths['/' . $descriptor->name] = [$descriptor->isAction() ? 'post' : 'get' => $this->operation($descriptor)];
            }
        }

        return [
            'openapi' => self::VERSION,
            'info' => ['title' => 'Frootbox API', 'version' => '1'],
            'servers' => [['url' => ApiHandler::PREFIX]],
            'paths' => [] === $paths ? new stdClass() : $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => ['Problem' => self::problemSchema()],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Descriptor $descriptor): array
    {
        $schema = $descriptor->schema();
        $operation = [
            'operationId' => $descriptor->name,
            'summary' => $descriptor->description,
            // Extensions by package, the core by area, e.g. "user".
            'tags' => [ActionName::package($descriptor->name) ?? explode('.', $descriptor->name)[0]],
        ];

        if (!$descriptor->public) {
            $operation['security'] = [['bearer' => []]];
        }

        if ($descriptor->isAction()) {
            $operation['requestBody'] = [
                'required' => [] !== ($schema['required'] ?? []),
                'content' => ['application/json' => ['schema' => $schema]],
            ];
        } elseif ([] !== $parameters = self::parameters($schema)) {
            $operation['parameters'] = $parameters;
        }

        $operation['responses'] = [
            '200' => [
                'description' => 'Success',
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['data' => new stdClass()],
                    'required' => ['data'],
                ]]],
            ],
            'default' => [
                'description' => 'Error',
                'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
            ],
        ];

        return $operation;
    }

    /**
     * Query parameters of a query. Lists use PHP's name[] notation.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<array<string, mixed>>
     */
    private static function parameters(array $schema): array
    {
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $parameters = [];

        foreach ($properties as $name => $property) {
            $isList = \is_array($property) && \in_array('array', (array) ($property['type'] ?? []), true);
            $parameters[] = [
                'name' => $isList ? $name . '[]' : (string) $name,
                'in' => 'query',
                'required' => \in_array($name, $required, true),
                'schema' => $property,
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private static function problemSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Problem details after RFC 9457.',
            'properties' => [
                'type' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'code' => ['type' => 'string', 'description' => 'For programs, e.g. "invalid-input" or "access-denied".'],
                'errors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['pointer' => ['type' => 'string'], 'message' => ['type' => 'string']],
                    ],
                ],
            ],
            'required' => ['title', 'status', 'code'],
        ];
    }
}
