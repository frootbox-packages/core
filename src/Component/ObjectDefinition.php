<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Field\Field;

/**
 * Further fields a view adds to the objects it shows (decisions 0234,
 * 0240), from "object:" in its header. Without a type they belong to the
 * objects of the plugin whose view the block is.
 */
final readonly class ObjectDefinition
{
    /**
     * @param array<string, Field> $fields
     */
    public function __construct(
        public ?string $type,
        public array $fields,
    ) {}
}
