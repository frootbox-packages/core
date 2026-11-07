<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Media\Images;
use Frootbox\Core\ProjectPaths;
use RuntimeException;

/**
 * The icon of the website (decision 0318): from the square PNG the theme
 * names under "icon" the core makes the favicon, the icon for Apple
 * devices and those of the web manifest, once per change of the file, in
 * var/cache/icon/. Browsers ask for these addresses even without a tag.
 */
final readonly class SiteIcon
{
    /** Address => size in pixels, 0 for the ICO with several sizes. */
    public const array FILES = [
        '/favicon.ico' => 0,
        '/apple-touch-icon.png' => 180,
        '/icon-192.png' => 192,
        '/icon-512.png' => 512,
    ];

    public const string MANIFEST = '/manifest.webmanifest';

    /** Apple devices show transparent areas black, so this one gets a background. */
    private const string APPLE = '/apple-touch-icon.png';

    /** Changes when the files are made differently, so old copies are left behind. */
    private const string REVISION = '2';

    /** The sizes inside favicon.ico. */
    private const array ICO_SIZES = [16, 32, 48];

    public function __construct(
        private ThemeChain $themes,
        private Images $images,
        private ProjectPaths $paths,
        private Config $config,
    ) {}

    public function available(): bool
    {
        return null !== $this->themes->icon();
    }

    /**
     * The tags for the head of a page, empty without an icon. The version
     * makes browsers load a changed icon.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $version = $this->version();

        if (null === $version) {
            return [];
        }

        $query = '?v=' . $version;

        return [
            '<link rel="icon" href="/favicon.ico' . $query . '" sizes="48x48">',
            '<link rel="icon" href="/icon-192.png' . $query . '" type="image/png" sizes="192x192">',
            '<link rel="apple-touch-icon" href="/apple-touch-icon.png' . $query . '">',
            '<link rel="manifest" href="' . self::MANIFEST . $query . '">',
        ];
    }

    /**
     * A short fingerprint of the icon, null without one.
     */
    public function version(): ?string
    {
        $icon = $this->themes->icon();

        if (null === $icon || !is_file($icon)) {
            return null;
        }

        // The background of the Apple icon counts too, and how the files are made.
        $background = $this->themes->tokens()['color-bg'] ?? '';

        return substr(hash('sha256', implode('|', [self::REVISION, $icon, filemtime($icon), filesize($icon), $background])), 0, 12);
    }

    /**
     * The path of a made file for an address of FILES, null without an
     * icon or for another address.
     */
    public function file(string $address): ?string
    {
        $icon = $this->themes->icon();
        $version = $this->version();
        $size = self::FILES[$address] ?? null;

        if (null === $icon || null === $version || null === $size) {
            return null;
        }

        $file = $this->paths->var('cache/icon/' . $version . '/' . basename($address));

        if (!is_file($file)) {
            $contents = match (true) {
                0 === $size => $this->ico($icon),
                self::APPLE === $address => $this->images->tile($icon, $size, self::color($this->themes->tokens()['color-bg'] ?? null) ?? '#ffffff'),
                default => $this->images->render($icon, $size, $size, 500, 500, 'png'),
            };
            self::write($file, $contents);
        }

        return $file;
    }

    /**
     * The web manifest with the name of the website and its icons.
     */
    public function manifest(): ?string
    {
        $version = $this->version();

        if (null === $version) {
            return null;
        }

        $name = $this->config->string('app.name', '');
        $manifest = array_filter([
            'name' => '' === $name ? null : $name,
            'start_url' => '/',
            'display' => 'browser',
            'icons' => [
                ['src' => '/icon-192.png?v=' . $version, 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icon-512.png?v=' . $version, 'sizes' => '512x512', 'type' => 'image/png'],
            ],
            'theme_color' => self::color($this->themes->tokens()['color-primary'] ?? null),
            'background_color' => self::color($this->themes->tokens()['color-bg'] ?? null),
        ], static fn(mixed $value): bool => null !== $value);

        return json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * An ICO file with PNG images inside, as browsers read it since 2006:
     * a header, one entry per size, then the images.
     */
    private function ico(string $icon): string
    {
        $images = array_map(fn(int $size): string => $this->images->render($icon, $size, $size, 500, 500, 'png'), self::ICO_SIZES);
        $offset = 6 + 16 * \count($images);
        $header = pack('vvv', 0, 1, \count($images));
        $entries = '';

        foreach ($images as $index => $png) {
            $size = self::ICO_SIZES[$index];
            $entries .= pack('CCCCvvVV', $size % 256, $size % 256, 0, 0, 1, 32, \strlen($png), $offset);
            $offset += \strlen($png);
        }

        return $header . $entries . implode('', $images);
    }

    /** A colour for the manifest, only a plain hex value of a token. */
    private static function color(?string $value): ?string
    {
        return null !== $value && 1 === preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/iD', $value) ? $value : null;
    }

    private static function write(string $file, string $contents): void
    {
        $folder = \dirname($file);

        if (!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) {
            throw new RuntimeException(\sprintf('The folder %s for the icon cannot be created.', $folder));
        }

        // Written aside and moved, so a second request never reads half a file.
        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (false === file_put_contents($temporary, $contents) || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException(\sprintf('The icon %s cannot be written.', $file));
        }
    }
}
