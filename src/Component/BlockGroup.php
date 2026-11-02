<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\ActionName;

/**
 * A group of the block picker by use, e.g. "teaser" (decision 0148). The
 * core names the common ones; an extension adds its own under its package
 * name, e.g. "acme/shop/products".
 */
final readonly class BlockGroup
{
    /** For the JSON schema of block headers, so without delimiters. */
    public const string NAME = '^(?:' . ActionName::PACKAGE . '/)?' . ActionName::SEGMENT . '$';

    public function __construct(
        public string $name,
        /** In English; the backend translates the groups of the core */
        public string $title,
    ) {}

    public static function isValid(string $name): bool
    {
        return 1 === preg_match('#' . self::NAME . '#', $name);
    }
}
