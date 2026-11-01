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

        $chain = new self(array_values($themes));
        $chain->check();

        return $chain;
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
     * The tokens editors may change, by name, children overriding their
     * parents (decision 0127). Contrast names the full token.
     *
     * @return array<string, TokenDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (array_reverse($this->themes) as $theme) {
            $definitions = [...$definitions, ...$theme->definitions];
        }

        foreach ($definitions as $name => $definition) {
            if (null !== $definition->contrast) {
                $definitions[$name] = $definition->withContrast($this->contrastOf($name, $definition->contrast, $definitions));
            }
        }

        return $definitions;
    }

    /**
     * The fields with content for every page as the manifests describe
     * them, by name (decision 0128); a child replaces a field of the same
     * name.
     *
     * @return array<string, array<mixed>>
     */
    public function fields(): array
    {
        $fields = [];

        foreach (array_reverse($this->themes) as $theme) {
            $fields = [...$fields, ...$theme->fields];
        }

        return $fields;
    }

    /**
     * The fonts a font token can choose: the system fonts, then those of
     * the themes, children overriding their parents.
     *
     * @return array<string, Font>
     */
    public function fonts(): array
    {
        $fonts = Font::system();

        foreach (array_reverse($this->themes) as $theme) {
            $fonts = [...$fonts, ...$theme->fonts];
        }

        return $fonts;
    }

    /**
     * The tokens as CSS variables, e.g. ":root{--color-primary:#0a6e4f;}".
     * Overrides are values editors chose; those the themes do not
     * describe or that do not fit are left out.
     *
     * @param array<string, string> $overrides
     */
    public function css(array $overrides = []): string
    {
        $tokens = $this->tokens();

        if ([] === $tokens) {
            return '';
        }

        $definitions = $this->definitions();
        $fonts = $this->fonts();
        $lines = '';

        foreach ($tokens as $name => $value) {
            $definition = $definitions[$name] ?? null;

            if (null !== $definition) {
                $override = $overrides[$name] ?? null;
                $value = $definition->css(null !== $override && null === $definition->problem($override, $fonts) ? $override : $value, $fonts);
            }

            $lines .= '--' . $name . ':' . $value . ';';
        }

        return ':root{' . $lines . '}';
    }

    /**
     * Checks the values the themes give their described tokens.
     *
     * @throws ThemeException
     */
    private function check(): void
    {
        $tokens = $this->tokens();
        $fonts = $this->fonts();

        foreach ($this->definitions() as $name => $definition) {
            $value = $tokens[$name] ?? '';
            $problem = $definition->problem($value, $fonts);

            if (null !== $problem) {
                throw new ThemeException(\sprintf('The token "%s" of the theme %s is "%s" but %s', $name, $this->active()?->name, $value, $problem));
            }
        }
    }

    /**
     * "primary-contrast" next to "color-primary" is "color-primary-contrast".
     *
     * @param array<string, TokenDefinition> $definitions
     *
     * @throws ThemeException
     */
    private function contrastOf(string $name, string $contrast, array $definitions): string
    {
        $cut = strrpos($name, '-');
        $candidates = false === $cut ? [$contrast] : [substr($name, 0, $cut + 1) . $contrast, $contrast];

        $tokens = $this->tokens();

        foreach ($candidates as $candidate) {
            $fixed = !isset($definitions[$candidate]) && 1 === preg_match(TokenDefinition::COLOR, $tokens[$candidate] ?? '');

            if ($fixed || TokenType::Color === ($definitions[$candidate]->type ?? null)) {
                return $candidate;
            }
        }

        throw new ThemeException(\sprintf('The token "%s" of the theme %s is read against "%s", which is no colour token.', $name, $this->active()?->name, $contrast));
    }
}
