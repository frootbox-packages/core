<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
     */
    public function __construct(public array $packages = []) {}

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
