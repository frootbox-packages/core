<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * The symbols of the themes for the field type "icon" (decision 0179):
 * SVG files in the folder icons/ of a theme, named in lowercase with
 * hyphens, e.g. icons/truck.svg. A child replaces a symbol of its parent
 * by the same name.
 *
 * The output goes into the page inline, hidden from screen readers: the
 * text next to it carries the meaning. Draw with currentColor, so the
 * symbol takes the color of the text around it.
 */
final class Icons
{
    public const string PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    /** @var array<string, array{file: string, theme: string}>|null By name, sorted */
    private ?array $files = null;

    /** @var array<string, string> */
    private array $svgs = [];

    public function __construct(private readonly ThemeChain $themes) {}

    /**
     * The names of all symbols, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->files());
    }

    public function has(string $name): bool
    {
        return isset($this->files()[$name]);
    }

    /**
     * The theme a symbol comes from, for the translation of its label.
     */
    public function themeOf(string $name): ?string
    {
        return $this->files()[$name]['theme'] ?? null;
    }

    /**
     * The symbol as markup for the page, or null if the theme has none of that name.
     */
    public function svg(string $name): ?string
    {
        if (isset($this->svgs[$name])) {
            return $this->svgs[$name];
        }

        $file = $this->files()[$name]['file'] ?? null;

        if (null === $file) {
            return null;
        }

        return $this->svgs[$name] = self::clean((string) file_get_contents($file), $file);
    }

    /**
     * The label of a symbol: its name with spaces, e.g. "map-pin" becomes "Map pin".
     */
    public static function label(string $name): string
    {
        return ucfirst(str_replace('-', ' ', $name));
    }

    /**
     * @return array<string, array{file: string, theme: string}>
     */
    private function files(): array
    {
        if (null !== $this->files) {
            return $this->files;
        }

        $files = [];

        // Parents first, so their children replace them.
        foreach (array_reverse($this->themes->themes) as $theme) {
            $found = glob($theme->path . '/icons/*.svg');

            foreach (false === $found ? [] : $found as $file) {
                $name = basename($file, '.svg');

                if (1 === preg_match('/' . self::PATTERN . '/', $name)) {
                    $files[$name] = ['file' => $file, 'theme' => $theme->name];
                }
            }
        }

        ksort($files);

        return $this->files = $files;
    }

    /**
     * Without XML declaration, doctype and comments, hidden from screen
     * readers and out of the tab order.
     */
    private static function clean(string $svg, string $file): string
    {
        $svg = trim((string) preg_replace(['/<\?xml.*?\?>/s', '/<!DOCTYPE[^>]*>/i', '/<!--.*?-->/s'], '', $svg));

        if (!str_starts_with($svg, '<svg') || !str_ends_with($svg, '</svg>')) {
            throw new ThemeException(\sprintf('%s is no SVG: it must start with <svg and end with </svg>.', $file));
        }

        $svg = (string) preg_replace('/\s(?:aria-hidden|focusable|role)="[^"]*"/', '', $svg);

        return '<svg aria-hidden="true" focusable="false"' . substr($svg, 4);
    }
}
