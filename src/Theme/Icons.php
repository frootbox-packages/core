<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaDrafts;
use Frootbox\Core\Media\MediaFiles;
use Frootbox\Core\Media\Medium;
use Frootbox\Core\Media\Svg;
use Frootbox\Core\Page\EditMode;

/**
 * The symbols for the field type "icon" (decisions 0179, 0182), in sets:
 *
 * - "theme": SVG files in icons/ of the theme chain with short names
 *   like "truck"; a child replaces a symbol of its parent by the same name.
 * - "<vendor>/<package>/<set>": SVG files in icons/<set>/ of an
 *   extension that is no theme, e.g. "acme/fontawesome/solid/truck".
 * - "media": the SVG graphics of the media library, "media:<ULID>".
 *
 * The output goes into the page inline, hidden from screen readers: the
 * text next to it carries the meaning. currentColor fills what a symbol
 * leaves open, so it takes the color of the text around it.
 */
final class Icons
{
    public const string THEME = 'theme';

    public const string MEDIA = 'media';

    private const string NAME = '[a-z0-9]+(?:-[a-z0-9]+)*';

    private const string PACKAGE = '[a-z0-9]+(?:[._-][a-z0-9]+)*\/[a-z0-9]+(?:[._-][a-z0-9]+)*';

    public const string PATTERN = '^(?:' . self::NAME . '|' . self::PACKAGE . '\/' . self::NAME . '\/' . self::NAME . '|media:[0-9A-HJKMNP-TV-Z]{26})$';

    /** @var array<string, array{file: string, theme: string}>|null By name, sorted */
    private ?array $themeFiles = null;

    /** @var array<string, array{package: string, folder: string}>|null By set, sorted */
    private ?array $folders = null;

    /** @var array<string, string> Cleaned files of themes and extensions */
    private array $clean = [];

    private int $count = 0;

    public function __construct(
        private readonly ThemeChain $themes,
        private readonly Extensions $extensions,
        private readonly Media $media,
        private readonly MediaDrafts $drafts,
        private readonly MediaFiles $files,
        private readonly EditMode $editMode,
    ) {}

    /**
     * The sets: the theme first, then those of extensions by name, the
     * media library last. The label of an extension set is the name of
     * its folder with spaces, for the translations of its package, with
     * the title of the package.
     *
     * @return array<string, array{label: string, package: ?string, title: ?string}>
     */
    public function sets(): array
    {
        $sets = [self::THEME => ['label' => 'Theme', 'package' => null, 'title' => null]];

        foreach ($this->folders() as $set => $folder) {
            $title = $this->extensions->get($folder['package'])?->title;
            $sets[$set] = ['label' => self::label(basename($set)), 'package' => $folder['package'], 'title' => $title];
        }

        return $sets + [self::MEDIA => ['label' => 'Own symbols', 'package' => null, 'title' => null]];
    }

    /**
     * The names of all symbols or of one set, in the order of the sets.
     *
     * @return list<string>
     */
    public function names(?string $set = null): array
    {
        $names = null === $set || self::THEME === $set ? array_keys($this->themeFiles()) : [];

        foreach ($this->folders() as $key => $folder) {
            if (null === $set || $key === $set) {
                foreach (self::glob($folder['folder'] . '/*.svg') as $file) {
                    $names[] = $key . '/' . basename($file, '.svg');
                }
            }
        }

        if (null === $set || self::MEDIA === $set) {
            foreach ($this->media->fetch(['where' => ['type' => Svg::TYPE], 'order' => 'fileName'])->all() as $medium) {
                $names[] = 'media:' . $medium->id;
            }
        }

        return $names;
    }

    public function has(string $name): bool
    {
        return match ($this->setOf($name)) {
            self::THEME => isset($this->themeFiles()[$name]),
            self::MEDIA => null !== $this->medium($name, true),
            null => false,
            default => null !== $this->file($name),
        };
    }

    /**
     * The set a name belongs to, null if it fits none.
     */
    public function setOf(string $name): ?string
    {
        if (1 !== preg_match('/' . self::PATTERN . '/', $name)) {
            return null;
        }

        if (str_starts_with($name, 'media:')) {
            return self::MEDIA;
        }

        return str_contains($name, '/') ? \dirname($name) : self::THEME;
    }

    /**
     * The package whose translations hold the label of a symbol: the
     * theme it comes from or the extension of its set.
     */
    public function packageOf(string $name): ?string
    {
        return match ($this->setOf($name)) {
            self::THEME => $this->themeFiles()[$name]['theme'] ?? null,
            self::MEDIA, null => null,
            default => $this->folders()[\dirname($name)]['package'] ?? null,
        };
    }

    /**
     * The label of a symbol before translation: its name with spaces, for
     * one of the media library its file name.
     */
    public function labelOf(string $name): string
    {
        if (self::MEDIA === $this->setOf($name)) {
            $medium = $this->medium($name, true);

            return null === $medium ? $name : pathinfo($medium->fileName, \PATHINFO_FILENAME);
        }

        return self::label(basename($name));
    }

    /**
     * The id of the medium a name stands for, null for other symbols.
     */
    public static function mediumId(string $name): ?string
    {
        return 1 === preg_match('/^media:([0-9A-HJKMNP-TV-Z]{26})$/', $name, $match) ? $match[1] : null;
    }

    /**
     * The symbol as markup for the page, or null if there is none of that
     * name. Each output gets its own prefix for ids and classes, so one
     * symbol can show several times on a page.
     *
     * @param bool $editor For those who edit, e.g. in the choice of icon.list: a graphic of the media library as its draft, also before it is published
     */
    public function svg(string $name, bool $editor = false): ?string
    {
        $clean = $this->clean($name, $editor);

        return null === $clean ? null : Svg::inline($clean, 'fbi' . ++$this->count);
    }

    /**
     * The label of a name: with spaces, e.g. "map-pin" becomes "Map pin".
     */
    public static function label(string $name): string
    {
        return ucfirst(str_replace('-', ' ', $name));
    }

    private function clean(string $name, bool $editor): ?string
    {
        $set = $this->setOf($name);

        if (self::MEDIA === $set) {
            $medium = $this->medium($name, $editor);

            // Cleaned when it came into the media library.
            $source = null === $medium ? false : @file_get_contents($this->files->original($medium->original()));

            return false === $source ? null : $source;
        }

        if (isset($this->clean[$name])) {
            return $this->clean[$name];
        }

        $file = match ($set) {
            self::THEME => $this->themeFiles()[$name]['file'] ?? null,
            null => null,
            default => $this->file($name),
        };

        if (null === $file) {
            return null;
        }

        $source = file_get_contents($file);

        return $this->clean[$name] = (false === $source ? null : Svg::clean($source))
            ?? throw new ThemeException(\sprintf('%s is no SVG with something to show.', $file));
    }

    /**
     * The SVG medium of a name: for an editor and in the editing mode its
     * draft, for visitors the published state once there is one.
     */
    private function medium(string $name, bool $editor): ?Medium
    {
        $id = self::mediumId($name);
        $medium = null === $id ? null : $this->media->fetchById($id);
        $editor = $editor || $this->editMode->active();

        if (null === $medium || !$medium->isVector() || (!$medium->public && !$editor)) {
            return null;
        }

        return $editor ? $this->drafts->current($medium) : $medium;
    }

    /**
     * The file of a symbol of an extension.
     */
    private function file(string $name): ?string
    {
        $folder = $this->folders()[\dirname($name)]['folder'] ?? null;
        $file = null === $folder ? null : $folder . '/' . basename($name) . '.svg';

        return null !== $file && is_file($file) ? $file : null;
    }

    /**
     * @return array<string, array{file: string, theme: string}>
     */
    private function themeFiles(): array
    {
        if (null !== $this->themeFiles) {
            return $this->themeFiles;
        }

        $files = [];

        // Parents first, so their children replace them.
        foreach (array_reverse($this->themes->themes) as $theme) {
            foreach (self::glob($theme->path . '/icons/*.svg') as $file) {
                $files[basename($file, '.svg')] = ['file' => $file, 'theme' => $theme->name];
            }
        }

        ksort($files);

        return $this->themeFiles = $files;
    }

    /**
     * @return array<string, array{package: string, folder: string}>
     */
    private function folders(): array
    {
        if (null !== $this->folders) {
            return $this->folders;
        }

        $folders = [];

        foreach ($this->extensions as $package) {
            if (null !== $package->theme || 1 !== preg_match('/^' . self::PACKAGE . '$/', $package->name)) {
                continue;
            }

            foreach (self::glob($package->path . '/icons/*', true) as $folder) {
                $folders[$package->name . '/' . basename($folder)] = ['package' => $package->name, 'folder' => $folder];
            }
        }

        ksort($folders);

        return $this->folders = $folders;
    }

    /**
     * Files, or folders with $folders, whose name fits, sorted.
     *
     * @return list<string>
     */
    private static function glob(string $pattern, bool $folders = false): array
    {
        $found = glob($pattern, $folders ? \GLOB_ONLYDIR : 0);
        $found = array_values(array_filter(
            false === $found ? [] : $found,
            static fn(string $path): bool => 1 === preg_match('/^' . self::NAME . '$/', basename($path, '.svg')) && ($folders || is_file($path)),
        ));
        sort($found);

        return $found;
    }
}
