<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * Whether the AI is there for a person (decision 0294); the interface
 * shows it only then (decision 0014).
 */
final readonly class AiStatus
{
    /** The person lacks the permission ai.use. */
    public const string NO_PERMISSION = 'permission';

    public function __construct(
        public bool $available,
        /** Why not: provider, setup, permission or limit */
        public ?string $reason,
        /** The title of the provider, e.g. "Anthropic" */
        public ?string $provider,
        /** The title of the chosen model */
        public ?string $model,
        /** Where its prices come from: config, hub or module (decision 0297) */
        public ?string $prices = null,
        /** Since when the list of the hub counts, as ISO 8601 */
        public ?string $pricesSince = null,
    ) {}
}
