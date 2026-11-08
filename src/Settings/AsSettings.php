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
 *
 * A package with more than one topic gives each further class a section,
 * e.g. section: "mail": it counts as "acme/shop.mail" in the table, the
 * actions, the backend and config/extensions/acme/shop.mail.yaml
 * (decision 0269).
 *
 * test: names a ConnectionTest that checks the settings against the
 * service they reach; the backend then offers "Test the connection"
 * (decision 0353).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSettings
{
    public function __construct(
        public string $title,
        public string $description = '',
        /** Lowercase letters, digits and dashes, e.g. "mail" */
        public ?string $section = null,
        /** @var class-string<ConnectionTest>|null */
        public ?string $test = null,
    ) {}
}
