<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

/**
 * A row as a typed, read-only object. Columns map to constructor
 * parameters by name, "parent_id" to $parentId:
 *
 *     final readonly class Page extends Entity
 *     {
 *         public function __construct(
 *             public int $id,
 *             public string $title,
 *             public ?int $parentId,
 *             public DateTimeImmutable $createdAt,
 *         ) {}
 *     }
 *
 * Changes go through actions and the repository, never through the entity.
 */
abstract readonly class Entity
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $values = [];

        foreach (new \ReflectionObject($this)->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $values[$property->getName()] = $property->getValue($this);
        }

        return $values;
    }
}
