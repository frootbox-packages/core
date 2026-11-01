<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

/**
 * What site.enter answers: the value of the cookie that lets the browser
 * in for 14 days.
 */
final readonly class SiteAccess
{
    public function __construct(
        public string $cookie,
        public int $days,
    ) {}
}
