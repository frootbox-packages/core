<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Extension\Extensions;

/**
 * The active theme and its parents, most specific first, e.g.
 * acme/website → frootbox/library → frootbox/base.
 *
 * The active theme is set in config/app.yaml: "theme: acme/website".
 */
final readonly class ThemeChain
{
    /**
     * @param list<Theme> $themes Active theme first
     */
    public function __construct(public array $themes = []) {}

    public static function resolve(Extensions $extensions, ?string $active): self
    {
        if (null === $active || '' === $active) {
            return new self();
        }

        $themes = [];
        $name = $active;

        while (null !== $name) {
            if (isset($themes[$name])) {
                throw new ThemeException(\sprintf('The themes %s inherit from each other in a circle.', implode(' → ', [...array_keys($themes), $name])));
            }

            $package = $extensions->get($name);

            if (null === $package) {
                throw new ThemeException($name === $active
                    ? \sprintf('The theme %s from config/app.yaml is not installed.', $name)
                    : \sprintf('The theme %s needs its parent %s, which is not installed.', array_key_last($themes), $name));
            }

            $theme = Theme::fromPackage($package)
                ?? throw new ThemeException(\sprintf('%s is not a theme: its frootbox.yaml has no "theme:" section.', $name));
            $themes[$name] = $theme;
            $name = $theme->parent;
        }

        return new self(array_values($themes));
    }

    public function active(): ?Theme
    {
        return $this->themes[0] ?? null;
    }

    public function contains(string $package): bool
    {
        return array_any($this->themes, static fn(Theme $theme): bool => $theme->name === $package);
    }

    /**
     * All tokens, children overriding their parents.
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        $tokens = [];

        foreach (array_reverse($this->themes) as $theme) {
            $tokens = [...$tokens, ...$theme->tokens];
        }

        return $tokens;
    }

    /**
     * The tokens as CSS variables, e.g. ":root{--color-primary:#0a6e4f;}".
     */
    public function css(): string
    {
        $tokens = $this->tokens();

        if ([] === $tokens) {
            return '';
        }

        return ':root{' . implode('', array_map(static fn(string $name, string $value): string => '--' . $name . ':' . $value . ';', array_keys($tokens), $tokens)) . '}';
    }
}
