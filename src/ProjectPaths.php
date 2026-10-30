<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core;

use InvalidArgumentException;

/**
 * Well-known folders of an installation.
 */
final readonly class ProjectPaths
{
    public string $root;

    public function __construct(string $root)
    {
        $realRoot = realpath($root);

        if (false === $realRoot || !is_dir($realRoot)) {
            throw new InvalidArgumentException(\sprintf('Project root "%s" is not a directory.', $root));
        }

        $this->root = $realRoot;
    }

    public function config(string $path = ''): string
    {
        return $this->join('config', $path);
    }

    public function public(string $path = ''): string
    {
        return $this->join('public', $path);
    }

    public function storage(string $path = ''): string
    {
        return $this->join('storage', $path);
    }

    public function var(string $path = ''): string
    {
        return $this->join('var', $path);
    }

    private function join(string $folder, string $path): string
    {
        $base = $this->root . \DIRECTORY_SEPARATOR . $folder;

        return '' === $path ? $base : $base . \DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
