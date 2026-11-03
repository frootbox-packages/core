<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

/**
 * What object.update-field saved: the value, its output and the entry in
 * the action log, for action.undo (decision 0241).
 */
final readonly class ObjectFieldUpdated
{
    public function __construct(
        public string $target,
        public string $language,
        public string $name,
        public mixed $value,
        /** The output of the field as HTML, as the website shows it */
        public string $html,
        /** The entry in the action log, null inside another action */
        public ?int $entry,
        /** @var array<string, string> The parts of the field as HTML, e.g. the caption of an image */
        public array $parts = [],
    ) {}
}
