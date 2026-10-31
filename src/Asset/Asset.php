<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Asset;

/**
 * A style or script file of a package, with the cascade layer its CSS
 * goes into (decision 0103).
 */
final readonly class Asset
{
    public function __construct(
        public string $file,
        public string $layer,
    ) {}

    public function isStyle(): bool
    {
        return str_ends_with($this->file, '.css');
    }

    public function isScript(): bool
    {
        return str_ends_with($this->file, '.js');
    }
}
