<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use InvalidArgumentException;

/**
 * Where the migrations of the core and of each extension live.
 *
 * Every package brings its own migrations in its own namespace.
 */
final class MigrationSources
{
    /** @var array<string, string> namespace => directory */
    private array $sources = [];

    /** @var array<string, string> namespace => package */
    private array $packages = [];

    public function add(string $namespace, string $directory, ?string $package = null): self
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException(\sprintf('Migration directory "%s" does not exist.', $directory));
        }

        $namespace = trim($namespace, '\\');
        $this->sources[$namespace] = $directory;

        if (null !== $package) {
            $this->packages[$namespace] = $package;
        }

        return $this;
    }

    /**
     * The package a migration belongs to, by the longest namespace that
     * holds its class (decision 0277).
     */
    public function packageOf(string $class): ?string
    {
        $class = trim($class, '\\');
        $found = null;

        foreach ($this->packages as $namespace => $package) {
            if (str_starts_with($class, $namespace . '\\') && (null === $found || \strlen($namespace) > \strlen($found))) {
                $found = $namespace;
            }
        }

        return null === $found ? null : $this->packages[$found];
    }

    /**
     * @return array<string, string> namespace => directory
     */
    public function all(): array
    {
        return $this->sources;
    }
}
