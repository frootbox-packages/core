<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

/**
 * What site.update-field saved: the value, its output and the entry in
 * the action log, for action.undo (decision 0128).
 */
final readonly class SiteFieldUpdated
{
    public function __construct(
        public string $language,
        public string $path,
        public mixed $value,
        /** The output of the field as HTML, as the pages show it */
        public string $html,
        /** The entry in the action log, null inside another action */
        public ?int $entry,
    ) {}
}
