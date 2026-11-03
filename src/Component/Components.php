<?php

/*
 * SPDX-License-Identifier: MIT
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
     * @param array<string, Component>             $blocks
     * @param array<string, Component>             $layouts
     * @param array<string, array<string, true>>   $hidden  Names by kind the themes do not offer (decision 0162)
     */
    public function __construct(
        public array $blocks = [],
        public array $layouts = [],
        public array $hidden = [],
    ) {}

    /**
     * Whether editors may add the block or choose the layout. Hidden ones
     * stay usable on pages that have them. The view of a plugin is never
     * placed, it belongs to plugin() (decision 0232).
     */
    public function offers(ComponentKind $kind, string $name): bool
    {
        $component = $this->all($kind)[$name] ?? null;

        return null !== $component && !isset($this->hidden[$kind->value][$name]) && !(ComponentKind::Block === $kind && $component->plugin);
    }

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
