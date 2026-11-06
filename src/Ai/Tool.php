<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * A tool the model may call, made from an action or query of the catalog.
 */
final readonly class Tool
{
    /**
     * @param array<string, mixed> $schema JSON Schema of the input
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $schema,
    ) {}
}
