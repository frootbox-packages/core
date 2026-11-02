<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Attribute;

/**
 * Names a source of the global search (decision 0214): "page" in the
 * core, "<vendor>/<package>/<name>" in an extension, e.g.
 * "acme/news/article". The title names its group of hits, in English.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSearchSource
{
    public function __construct(
        public string $name,
        public string $title,
    ) {}
}
