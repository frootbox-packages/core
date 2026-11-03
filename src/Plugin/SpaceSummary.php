<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

/**
 * A space of a plugin as screens and the REST API show it (decision 0232).
 */
final readonly class SpaceSummary
{
    public function __construct(
        public string $id,
        public string $title,
        /** The default space of the type, without a row */
        public bool $default,
        /** The page that shows the space, published or in a draft */
        public ?string $pageId = null,
    ) {}
}
