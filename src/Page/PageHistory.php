<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * A page of the history of a page, newest first; next is where the older
 * entries start (pass as before), if there are more.
 */
final readonly class PageHistory
{
    /**
     * @param list<PageHistoryEntry> $entries
     */
    public function __construct(
        public array $entries,
        public ?int $next,
    ) {}
}
