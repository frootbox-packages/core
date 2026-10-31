<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

use Attribute;

/**
 * Marks a field type. The core uses plain names such as "headline", an
 * extension puts its package in front, e.g. "acme/shop/price".
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsFieldType
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {}
}
