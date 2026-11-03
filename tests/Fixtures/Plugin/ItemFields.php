<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin;

use Frootbox\Core\ObjectField\SpaceFieldSource;

/**
 * Fields per space of the items (decision 0250), as a module would read
 * them from its table; tests set them.
 */
final class ItemFields implements SpaceFieldSource
{
    /** @var array<string, array<string, array<string, mixed>>> By space */
    public array $spaces = [];

    public function fields(string $space): array
    {
        return $this->spaces[$space] ?? [];
    }

    public function name(string $space): string
    {
        return \sprintf('Space "%s"', $space);
    }
}
