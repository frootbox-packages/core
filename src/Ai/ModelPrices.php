<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use DateTimeImmutable;

/**
 * The prices a model counts with and where they come from (decision 0297).
 */
final readonly class ModelPrices
{
    /** From "ai.prices" in config/app.yaml, e.g. for an own contract. */
    public const string CONFIG = 'config';

    /** From the list of the hub. */
    public const string HUB = 'hub';

    /** From the provider module. */
    public const string MODULE = 'module';

    public function __construct(
        public Prices $prices,
        /** @var self::CONFIG|self::HUB|self::MODULE */
        public string $source,
        /** When the website got the list of the hub */
        public ?DateTimeImmutable $since = null,
    ) {}
}
