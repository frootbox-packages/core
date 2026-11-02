<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

/**
 * The installed extensions in load order: dependencies first, project
 * extensions last.
 *
 * @implements \IteratorAggregate<int, ExtensionPackage>
 */
final readonly class Extensions implements \IteratorAggregate, \Countable
{
    /**
     * @param list<ExtensionPackage> $packages
     * @param string                 $fingerprint Changes when packages are installed, updated or removed
     */
    public function __construct(
        public array $packages = [],
        public string $fingerprint = '',
    ) {}

    /**
     * @return \ArrayIterator<int, ExtensionPackage>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->packages);
    }

    public function count(): int
    {
        return \count($this->packages);
    }

    public function get(string $name): ?ExtensionPackage
    {
        foreach ($this->packages as $package) {
            if ($package->name === $name) {
                return $package;
            }
        }

        return null;
    }
}
