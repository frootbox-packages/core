<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * Prices of a model in US dollars per million tokens (decision 0294).
 */
final readonly class Prices
{
    public function __construct(
        public float $input,
        public float $output,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
    ) {}

    /**
     * The cost in millionths of a dollar: a price per million tokens
     * times the tokens is exactly that.
     */
    public function cost(Usage $usage): int
    {
        return (int) round(
            $usage->input * $this->input
            + $usage->output * $this->output
            + $usage->cacheRead * $this->cacheRead
            + $usage->cacheWrite * $this->cacheWrite,
        );
    }
}
