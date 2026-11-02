<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

/**
 * What one run of the worker did.
 */
final readonly class WorkerResult
{
    public function __construct(
        public int $done = 0,
        public int $retried = 0,
        public int $failed = 0,
    ) {}

    public function total(): int
    {
        return $this->done + $this->retried + $this->failed;
    }
}
