<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Asset;

use Frootbox\Core\Config\Environment;
use Frootbox\RichText\Html;
use RuntimeException;

/**
 * The <style>, <link> and <script> tags for an asset list (decision 0103).
 *
 * In development every file is loaded on its own, CSS through
 * "@import url(…) layer(…)", so changes show on the next request and the
 * browser tools name the real files. Live the CSS of a page becomes one
 * file in public/assets/bundles, named after the files and their changes,
 * so pages with the same blocks share it. Scripts stay ES modules.
 */
final readonly class AssetTags
{
    public const string BUNDLES = 'bundles';

    public function __construct(
        private PublicAssets $public,
        private Environment $environment,
    ) {}

    public function styles(AssetList $assets): string
    {
        $styles = $assets->styles();

        if ([] === $styles) {
            return '';
        }

        if ($this->environment->isDevelopment()) {
            $imports = array_map(
                fn(Asset $asset): string => \sprintf('@import url("%s") layer(%s);', $this->versioned($asset), $asset->layer),
                $styles,
            );

            return "<style>\n" . implode("\n", $imports) . "\n</style>";
        }

        return \sprintf('<link rel="stylesheet" href="%s">', Html::escape($this->bundle($styles)));
    }

    public function scripts(AssetList $assets): string
    {
        return implode("\n", array_map(
            fn(Asset $asset): string => \sprintf('<script type="module" src="%s"></script>', Html::escape($this->versioned($asset))),
            $assets->scripts(),
        ));
    }

    private function versioned(Asset $asset): string
    {
        return $this->public->versioned($asset->file);
    }

    /**
     * The URL of the bundle with these styles, built on first use.
     *
     * @param list<Asset> $styles
     */
    private function bundle(array $styles): string
    {
        $key = array_map(static fn(Asset $asset): array => [$asset->file, $asset->layer, filemtime($asset->file), filesize($asset->file)], $styles);
        $name = substr(hash('sha256', serialize($key)), 0, 16) . '.css';
        $file = $this->public->directory(self::BUNDLES . '/' . $name);

        if (!is_file($file)) {
            $css = '';

            foreach ($styles as $asset) {
                $content = file_get_contents($asset->file);

                if (false === $content) {
                    throw new RuntimeException(\sprintf('Could not read %s.', $asset->file));
                }

                $url = $this->public->url($asset->file);
                $css .= \sprintf("/* %s */\n@layer %s {\n%s\n}\n", $url, $asset->layer, self::absoluteUrls($content, \dirname($url)));
            }

            self::write($file, $css);
        }

        return PublicAssets::PATH . '/' . self::BUNDLES . '/' . $name;
    }

    /**
     * Relative url(…) in CSS pointing to the published folder, since the
     * bundle lives elsewhere.
     */
    private static function absoluteUrls(string $css, string $base): string
    {
        return (string) preg_replace_callback(
            '/url\(\s*([\'"]?)(?![a-z][a-z0-9+.-]*:|\/|#)([^\'")]+)\1\s*\)/i',
            static fn(array $match): string => \sprintf('url("%s/%s")', $base, $match[2]),
            $css,
        );
    }

    private static function write(string $file, string $content): void
    {
        $directory = \dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Could not create %s. Is public/assets writable?', $directory));
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (false === file_put_contents($temporary, $content) || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException(\sprintf('Could not write %s.', $file));
        }
    }
}
