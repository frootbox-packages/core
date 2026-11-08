<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

/**
 * The answer of a test of the connection (decision 0353).
 */
final readonly class ConnectionResult
{
    public function __construct(
        public string $package,
        /** Whether the service answered as expected */
        public bool $ok,
        /** The sentence of the test, translated */
        public string $message,
        public float $seconds,
    ) {}
}
