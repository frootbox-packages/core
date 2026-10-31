<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Loads Twig files by their absolute path, as resolved through the theme
 * chain by the ComponentLoader.
 */
final class FileLoader implements LoaderInterface
{
    public function getSourceContext(string $name): Source
    {
        $this->assertExists($name);

        return new Source((string) file_get_contents($name), $name, $name);
    }

    public function getCacheKey(string $name): string
    {
        $this->assertExists($name);

        return $name;
    }

    public function isFresh(string $name, int $time): bool
    {
        $this->assertExists($name);

        return filemtime($name) < $time;
    }

    public function exists(string $name): bool
    {
        return str_starts_with($name, '/') && str_ends_with($name, '.twig') && is_file($name);
    }

    private function assertExists(string $name): void
    {
        if (!$this->exists($name)) {
            throw new LoaderError(\sprintf('The template "%s" does not exist.', $name));
        }
    }
}
