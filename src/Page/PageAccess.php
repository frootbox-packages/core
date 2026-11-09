<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * What page.enter answers: the cookie that lets the browser into the
 * protected page for 14 days (decision 0358).
 */
final readonly class PageAccess
{
    public function __construct(
        public string $cookie,
        public string $value,
        public int $days,
    ) {}
}
