<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Asset\PublicAssets;
use Frootbox\Core\Theme\Font;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\RichText\Html;

/**
 * The fonts the themes ship as files (decision 0165): their @font-face,
 * an adjusted fallback so nothing moves while they load, and preloads
 * for the files the tokens of the page use. Files come only from this
 * website, never from a server of others.
 */
final readonly class WebFonts
{
    public function __construct(
        private ThemeChain $themes,
        private DesignTokens $design,
        private PublicAssets $assets,
    ) {}

    /**
     * Every @font-face; browsers load a file only for text that needs it.
     */
    public function css(): string
    {
        $css = '';

        foreach ($this->withFiles() as $font) {
            foreach ($font->files as $file) {
                $css .= '@font-face{font-family:"' . $font->face . '";'
                    . 'src:url("' . $this->assets->versioned($file->path) . '") format("' . $file->format() . '");'
                    . 'font-weight:' . $file->weight . ';font-style:' . $file->style . ';font-display:swap;'
                    . (null === $file->range ? '' : 'unicode-range:' . $file->range . ';')
                    . '}';
            }

            if (null !== $font->fallback) {
                $css .= $font->fallback->css($font->fallbackFace());
            }
        }

        return $css;
    }

    /**
     * The preload links for the files marked so of the fonts the tokens use.
     *
     * @return list<string>
     */
    public function preloads(): array
    {
        $fonts = $this->withFiles();
        $links = [];

        foreach ($this->design->usedFonts() as $key) {
            foreach (isset($fonts[$key]) ? $fonts[$key]->files : [] as $file) {
                if ($file->preload) {
                    $links[] = \sprintf('<link rel="preload" href="%s" as="font" type="font/%s" crossorigin>', Html::escape($this->assets->versioned($file->path)), Html::escape($file->format()));
                }
            }
        }

        return $links;
    }

    /**
     * @return array<string, Font>
     */
    private function withFiles(): array
    {
        return array_filter($this->themes->fonts(), static fn(Font $font): bool => [] !== $font->files);
    }
}
