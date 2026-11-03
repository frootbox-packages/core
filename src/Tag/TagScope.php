<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

/**
 * A closed list of tags: for a type, or for one space of it, editors
 * choose only from the presets and type no tags of their own.
 *
 *     $registry->add(TagScope::class, new TagScope('frootbox/jobs/job', closed: true));
 */
final readonly class TagScope
{
    /**
     * @param string|null $space Id or name of a space of the type
     */
    public function __construct(
        public string $type,
        public ?string $space = null,
        public bool $closed = true,
    ) {}
}
