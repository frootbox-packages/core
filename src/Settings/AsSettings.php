<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Attribute;

/**
 * The settings of an extension (decision 0221): a final readonly class
 * whose public promoted properties with defaults are the settings.
 *
 *     #[AsSettings('Shop', 'Connection to the shop system')]
 *     final readonly class ShopSettings
 *     {
 *         public function __construct(
 *             #[Title('Address of the shop')]
 *             public string $url = '',
 *             #[Title('API key'), Sensitive]
 *             public string $apiKey = '',
 *         ) {}
 *     }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSettings
{
    public function __construct(
        public string $title,
        public string $description = '',
    ) {}
}
