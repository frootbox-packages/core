<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * A model of a provider with its prices.
 */
final readonly class Model
{
    public function __construct(
        /** The id of the provider, e.g. "claude-opus-5-5" */
        public string $id,
        public string $title,
        public Prices $prices,
    ) {}
}
