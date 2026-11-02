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

    public function add(string $namespace, string $directory): self
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException(\sprintf('Migration directory "%s" does not exist.', $directory));
        }

        $this->sources[trim($namespace, '\\')] = $directory;

        return $this;
    }

    /**
     * @return array<string, string> namespace => directory
     */
    public function all(): array
    {
        return $this->sources;
    }
}
