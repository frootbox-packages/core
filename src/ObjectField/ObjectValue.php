<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Database\Entity;

/**
 * The further fields of one published object (decision 0240), by name;
 * a field per language holds {"de": …, "en": …}.
 */
final readonly class ObjectValue extends Entity
{
    /**
     * @param array<mixed> $fields
     */
    public function __construct(
        public int $id,
        public string $targetType,
        public string $targetId,
        public array $fields,
    ) {}
}
