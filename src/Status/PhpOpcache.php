<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Status;

/**
 * The state of OPcache for this SAPI.
 */
final readonly class PhpOpcache
{
    public function __construct(
        public bool $enabled,
        public ?int $memoryUsed = null,
        public ?int $memoryFree = null,
        public ?float $hitRate = null,
        public bool $jit = false,
    ) {}
}
