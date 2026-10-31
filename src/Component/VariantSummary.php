<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * A variant of a layout or block, e.g. "Default" or "Wide".
 */
final readonly class VariantSummary
{
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
    ) {}
}
