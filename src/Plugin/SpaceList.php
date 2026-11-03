<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

/**
 * The spaces of a plugin, the default one first (decision 0232).
 */
final readonly class SpaceList
{
    /**
     * @param list<SpaceSummary> $items
     */
    public function __construct(
        public string $type,
        public string $label,
        /** Whether the plugin allows more spaces than the default one */
        public bool $several,
        public array $items,
        /** Pages of their own for the objects: required, optional or none */
        public Offer $details = Offer::Required,
    ) {}
}
