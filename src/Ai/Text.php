<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

final readonly class Text implements Part
{
    public function __construct(public string $text) {}
}
