<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeChain;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Loads Twig files by their absolute path, as resolved through the theme
 * chain by the ComponentLoader, and partials of the themes by name
 * (decision 0108):
 *
 *     {% include '@theme/header.twig' %}
 *
 * finds partials/header.twig in the active theme or else its parents.
 */
final class FileLoader implements LoaderInterface
{
    public const string NAMESPACE = '@theme/';

    private const string PARTIAL = '/^@theme\/(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_-]+\.twig$/';

    public function __construct(private readonly ThemeChain $themes = new ThemeChain()) {}

    public function getSourceContext(string $name): Source
    {
        $file = $this->file($name);

        return new Source((string) file_get_contents($file), $name, $file);
    }

    public function getCacheKey(string $name): string
    {
        return $this->file($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        return filemtime($this->file($name)) < $time;
    }

    public function exists(string $name): bool
    {
        return null !== $this->find($name);
    }

    private function find(string $name): ?string
    {
        if (str_starts_with($name, self::NAMESPACE)) {
            return 1 === preg_match(self::PARTIAL, $name) ? $this->partial(substr($name, \strlen(self::NAMESPACE))) : null;
        }

        return str_starts_with($name, '/') && str_ends_with($name, '.twig') && is_file($name) ? $name : null;
    }

    private function partial(string $path): ?string
    {
        foreach ($this->themes->themes as $theme) {
            $file = $theme->path . '/partials/' . $path;

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    private function file(string $name): string
    {
        $file = $this->find($name);

        if (null !== $file) {
            return $file;
        }

        if (!str_starts_with($name, self::NAMESPACE)) {
            throw new LoaderError(\sprintf('The template "%s" does not exist.', $name));
        }

        throw new LoaderError(1 === preg_match(self::PARTIAL, $name)
            ? \sprintf(
                'The partial "%s" does not exist. Looked in partials/ of %s.',
                $name,
                [] === $this->themes->themes ? 'no theme, there is no active one' : implode(', ', array_map(static fn(Theme $theme): string => $theme->name, $this->themes->themes)),
            )
            : \sprintf('"%s" is no valid partial name: letters, digits, "-", "_" and "/", ending in .twig, e.g. "@theme/header.twig".', $name));
    }
}
