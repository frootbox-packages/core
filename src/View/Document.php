<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Asset\AssetTags;
use Frootbox\Core\Asset\PublicAssets;
use Frootbox\Core\Design\DesignTokens;
use Frootbox\Core\Design\WebFonts;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Url\Target;
use Frootbox\RichText\Html;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Writes the HTML document around the body a layout rendered (decision
 * 0104): language, title, canonical, hreflang, the order of the cascade layers,
 * the theme tokens and the styles and scripts of the page.
 */
final readonly class Document
{
    /** The style of the badge on images made with AI (decision 0251). */
    public const string AI_STYLE = 'assets/ai.css';
    public function __construct(
        private AssetTags $tags,
        private ThemeChain $themes,
        private EventDispatcherInterface $events,
        private Languages $languages,
        private DesignTokens $design,
        private WebFonts $fonts,
    ) {}

    public function render(Head $head, string $body, AssetList $assets, ServerRequestInterface $request, ?Target $target = null): string
    {
        $this->addThemeAssets($assets);

        // The badge of an image made with AI, only where there is one (decision 0251).
        if (str_contains($body, 'data-fb-ai=')) {
            $assets->add(\dirname(__DIR__, 2) . '/' . self::AI_STYLE, 'base');
        }

        $this->events->dispatch(new BuildingHead($head, $request, $target));

        $lines = [
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . Html::escape($head->title) . '</title>',
        ];

        // One line, also for a teaser with line breaks.
        $description = trim((string) preg_replace('/\s+/u', ' ', $head->description));

        if ('' !== $description) {
            $lines[] = '<meta name="description" content="' . Html::escape($description) . '">';
        }

        if (null !== $head->canonical) {
            $lines[] = '<link rel="canonical" href="' . Html::escape($head->canonical) . '">';
        }

        $alternates = $head->alternates();

        if (\count($alternates) > 1) {
            foreach ($alternates as $language => $url) {
                $lines[] = \sprintf('<link rel="alternate" hreflang="%s" href="%s">', Html::escape($language), Html::escape($url));
            }

            // The default language, the first one configured, for everyone else.
            $lines[] = \sprintf('<link rel="alternate" hreflang="x-default" href="%s">', Html::escape($this->defaultAlternate($alternates)));
        }

        // Fonts first, so the browser starts loading them early (decision 0165).
        $lines = [...$lines, ...$this->fonts->preloads()];
        $lines[] = '<style>' . $assets->layerOrder() . '</style>';
        $faces = $this->fonts->css();

        if ('' !== $faces) {
            $lines[] = '<style>' . $faces . '</style>';
        }

        foreach ($assets->inlineStyles() as $css) {
            $lines[] = '<style>' . $css . '</style>';
        }

        // With the values editors chose, in the editing mode those of the draft.
        $tokens = $this->design->css();

        if ('' !== $tokens) {
            $lines[] = '<style>@layer tokens {' . $tokens . '}</style>';
        }

        $lines = [...$lines, $this->tags->styles($assets), ...$head->tags(), $this->tags->scripts($assets)];

        return \sprintf(
            "<!doctype html>\n<html lang=\"%s\" dir=\"%s\">\n<head>\n%s\n</head>\n<body>\n%s\n</body>\n</html>\n",
            Html::escape($head->language),
            Html::escape($head->direction),
            implode("\n", array_filter($lines, static fn(string $line): bool => '' !== $line)),
            $body,
        );
    }

    /**
     * @param non-empty-array<string, string> $alternates
     */
    private function defaultAlternate(array $alternates): string
    {
        return $alternates[$this->languages->default()->code] ?? $alternates[array_key_first($alternates)];
    }

    /**
     * Styles and scripts directly in assets/ of the themes, parents
     * first. They are on every page, in the layer "theme", unless named
     * after a layer: reset.css goes into "reset", base.css into "base".
     * A child replaces a file of its parents by giving its own the same
     * name, like a partial; an empty file switches it off, e.g. reset.css
     * for a framework that brings its own.
     */
    private function addThemeAssets(AssetList $assets): void
    {
        $chosen = [];

        foreach (array_reverse($this->themes->themes) as $theme) {
            $files = array_filter(
                self::files($theme->path . '/' . PublicAssets::FOLDER),
                static fn(string $file): bool => str_ends_with($file, '.css') || str_ends_with($file, '.js'),
            );
            sort($files);

            foreach ($files as $file) {
                // A replaced file keeps the place of the parent's, so the order stays.
                $chosen[basename($file)] = $file;
            }
        }

        foreach ($chosen as $file) {
            if (0 === filesize($file)) {
                continue;
            }

            $name = pathinfo($file, \PATHINFO_FILENAME);
            $assets->add($file, \in_array($name, AssetList::LAYERS, true) && 'tokens' !== $name ? $name : 'theme');
        }
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        $files = glob($directory . '/*');

        return false === $files ? [] : $files;
    }
}
