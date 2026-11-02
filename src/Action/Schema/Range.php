<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use Attribute;

/**
 * Smallest and largest allowed number.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Range
{
    public function __construct(public int|float|null $min = null, public int|float|null $max = null) {}
}
