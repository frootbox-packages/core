<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action\Schema;

use Attribute;

/**
 * Explains an input field to people and the AI.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Description
{
    public function __construct(public string $text) {}
}
