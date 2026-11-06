<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * What the AI cost in a month (decision 0294), amounts in US dollars.
 */
final readonly class UsageReport
{
    /**
     * @param list<array{date: string, calls: int, cost: float}>                                         $days
     * @param list<array{feature: string, calls: int, cost: float}>                                      $features
     * @param list<array{userId: int|null, name: string, calls: int, cost: float}>                       $people
     * @param list<array{model: string, calls: int, inputTokens: int, outputTokens: int, cost: float}> $models
     */
    public function __construct(
        /** E.g. "2026-11" */
        public string $month,
        public ?string $provider,
        public ?string $model,
        /** The monthly limit, null for none */
        public ?float $limit,
        public float $spent,
        /** What is left of the limit, null without one */
        public ?float $remaining,
        public int $calls,
        public array $days,
        public array $features,
        public array $people,
        public array $models,
    ) {}
}
