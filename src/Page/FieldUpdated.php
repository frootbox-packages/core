<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * What page.update-field saved: the value, its output and the entry in
 * the action log, for action.undo (decision 0122).
 */
final readonly class FieldUpdated
{
    public function __construct(
        public string $id,
        public string $language,
        public ?string $block,
        public string $path,
        public mixed $value,
        /** The output of the field as HTML, as the page shows it */
        public string $html,
        /** The entry in the action log, null inside another action */
        public ?int $entry,
    ) {}
}
