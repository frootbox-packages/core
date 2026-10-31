<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
    ) {}
}
