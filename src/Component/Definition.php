<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Field\Field;

/**
 * What a block.yaml or the header of a view declares, checked.
 */
final readonly class Definition
{
    /**
     * @param array<string, Option> $options
     * @param array<string, Field>  $fields
     * @param array<string, Area>   $areas
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public array $options = [],
        public array $fields = [],
        public array $areas = [],
        /** Only for areas that allow the block by name (decision 0124) */
        public bool $widget = false,
        /** The group in the block picker (decision 0148) */
        public ?string $group = null,
        /** @var list<string> Further words for the search */
        public array $keywords = [],
        /** @var list<array<string, mixed>> Content for the design page (decisions 0205, 0206) */
        public array $examples = [],
        /** @var list<string> Query parameters the block reads, e.g. "page" (decision 0219) */
        public array $query = [],
    ) {}
}
