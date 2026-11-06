<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * The answer to "test the connection" (decision 0294).
 */
final readonly class TestResult
{
    public function __construct(
        public string $provider,
        /** The model that answered */
        public string $model,
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        /** In US dollars */
        public float $cost,
        public float $seconds,
    ) {}
}
