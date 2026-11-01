<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

use Closure;

/**
 * A field type whose output holds headings, e.g. rich text. On a page the
 * core decides their levels from where the field stands (decision 0129).
 */
interface OutputsHeadings
{
    /**
     * Like output(), with the level of each heading from the page.
     *
     * @param Closure(int, string): int $level The level for a rank (1 and deeper) and the rendered content of the heading
     */
    public function outputWithHeadings(mixed $value, Field $field, Closure $level): mixed;

    /**
     * How many ranks of headings the field offers, 0 for none.
     */
    public function headingRanks(Field $field): int;
}
