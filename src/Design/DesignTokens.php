<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Theme\TokenType;

/**
 * The values editors chose for the tokens of the active theme (decision
 * 0127), kept as a revision of the type "design" per theme. Visitors get
 * the published ones, the editing mode the draft.
 */
final readonly class DesignTokens
{
    public const string TYPE = 'design';

    public function __construct(
        private ThemeChain $themes,
        private Revisions $revisions,
        private EditMode $editMode,
        private Config $config,
        private Languages $languages,
    ) {}

    /**
     * The tokens as CSS variables for the page being delivered.
     */
    public function css(): string
    {
        $database = $this->config->get('database.url');

        // Before the installation there is nothing to read.
        if (!\is_string($database) || '' === $database) {
            return $this->themes->css([], $this->scripts());
        }

        return $this->themes->css($this->overrides($this->editMode->active()), $this->scripts());
    }

    /**
     * The keys of the fonts the font tokens use on this page, with the
     * values editors chose (decision 0165).
     *
     * @return list<string>
     */
    public function usedFonts(): array
    {
        $database = $this->config->get('database.url');
        $overrides = \is_string($database) && '' !== $database ? $this->overrides($this->editMode->active()) : [];
        $tokens = $this->themes->tokens();
        $fonts = $this->themes->fonts();
        $used = [];

        foreach ($this->themes->definitions() as $name => $definition) {
            if (TokenType::Font !== $definition->type) {
                continue;
            }

            $override = $overrides[$name] ?? null;
            $value = null !== $override && null === $definition->problem($override, $fonts) ? $override : $tokens[$name] ?? '';

            if (isset($fonts[$value])) {
                $used[] = $value;
            }
        }

        return array_values(array_unique($used));
    }

    /**
     * The codes of the languages of the website by their script (decision 0159).
     *
     * @return array<string, list<string>>
     */
    private function scripts(): array
    {
        $scripts = [];

        foreach ($this->languages->all() as $language) {
            $scripts[$language->script][] = $language->code;
        }

        return $scripts;
    }

    /**
     * The chosen values by token name; with $draft those of the draft if
     * there is one.
     *
     * @return array<string, string>
     */
    public function overrides(bool $draft): array
    {
        $theme = $this->themes->active();

        if (null === $theme) {
            return [];
        }

        $revision = ($draft ? $this->revisions->draft(self::TYPE, $theme->name) : null) ?? $this->revisions->published(self::TYPE, $theme->name);

        return self::values($revision);
    }

    /**
     * @return array<string, string>
     */
    public static function values(?Revision $revision): array
    {
        $tokens = $revision?->data['tokens'] ?? null;
        $values = [];

        foreach (\is_array($tokens) ? $tokens : [] as $name => $value) {
            if (\is_string($value)) {
                $values[(string) $name] = $value;
            }
        }

        return $values;
    }
}
