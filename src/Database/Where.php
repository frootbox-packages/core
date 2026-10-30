<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

/**
 * Alternatives in a where clause: at least one group must match.
 *
 *     $news->fetch(['where' => [
 *         'status' => 'published',
 *         Where::any(['featured' => true], ['publishedAt >=' => $lastWeek]),
 *     ]]);
 */
final readonly class Where
{
    /**
     * @param list<array<int|string, mixed>> $groups
     */
    private function __construct(public array $groups) {}

    /**
     * @param array<int|string, mixed> ...$groups
     */
    public static function any(array ...$groups): self
    {
        return new self(array_values($groups));
    }
}
