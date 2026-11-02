<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use Attribute;

/**
 * A regular expression the string must match, without delimiters,
 * e.g. "^[a-z0-9-]+$".
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Pattern
{
    public function __construct(public string $pattern) {}
}
