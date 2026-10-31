<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * The blocks and layouts available in this installation: those of all
 * extensions that are not themes plus those of the active theme chain.
 */
final readonly class Components
{
    /**
     * @param array<string, Component> $blocks
     * @param array<string, Component> $layouts
     */
    public function __construct(
        public array $blocks = [],
        public array $layouts = [],
    ) {}

    public function get(ComponentKind $kind, string $name): ?Component
    {
        return $this->all($kind)[$name] ?? null;
    }

    public function block(string $name): ?Component
    {
        return $this->blocks[$name] ?? null;
    }

    public function layout(string $name): ?Component
    {
        return $this->layouts[$name] ?? null;
    }

    /**
     * @return array<string, Component>
     */
    public function all(ComponentKind $kind): array
    {
        return ComponentKind::Block === $kind ? $this->blocks : $this->layouts;
    }
}
