<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * The tokens one model used for an answer. A provider that hands a
 * refused request to another model returns one per model.
 */
final readonly class Usage
{
    public function __construct(
        /** The model that used them */
        public string $model,
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
    ) {}
}
