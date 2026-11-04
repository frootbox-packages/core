<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * A block or layout and the pages that use it (decision 0276).
 */
final readonly class ComponentUsage
{
    /**
     * @param string          $name      full name, e.g. "frootbox/news/List"
     * @param string          $package   the extension it belongs to, e.g. "frootbox/news"
     * @param bool            $installed false if its extension is gone: the pages keep it as it was
     * @param list<UsingPage> $pages     in the draft or published, by title
     */
    public function __construct(
        public string $name,
        public ComponentKind $kind,
        public string $package,
        public bool $installed,
        public array $pages,
    ) {}
}
