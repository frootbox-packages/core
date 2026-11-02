<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use Attribute;

/**
 * Name and description of a job (decision 0221). An extension names it
 * "<vendor>/<package>/…" like its actions.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsJob
{
    /**
     * @param int $attempts how often it runs before it counts as failed; the
     *                      pause between attempts grows from 1 to 3, 9, 27 … minutes
     */
    public function __construct(
        public string $name,
        public string $description,
        public int $attempts = 8,
    ) {}
}
