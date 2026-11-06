<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Cache;

/**
 * What system.cache-clear did.
 */
final readonly class CachesCleared
{
    /**
     * @param int $files the files removed from var/cache
     */
    public function __construct(
        public int $files,
    ) {}
}
