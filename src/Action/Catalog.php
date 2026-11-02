<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * All registered actions and queries, by name and by class.
 */
final class Catalog
{
    /** @var array<string, Descriptor> */
    private array $byName = [];

    /** @var array<class-string, Descriptor> */
    private array $byClass = [];

    /**
     * Adds an action or query of an extension package, or of the core
     * when $package is null. Its name must start with the package name.
     *
     * @param class-string $class
     */
    public function add(string $class, ?string $package = null): Descriptor
    {
        $descriptor = Descriptor::of($class);
        self::assertOwnedBy($descriptor, $package);
        $existing = $this->byName[$descriptor->name] ?? null;

        if (null !== $existing) {
            throw new DefinitionException(\sprintf('The name "%s" is registered twice: %s and %s.', $descriptor->name, $existing->class, $class));
        }

        $this->byName[$descriptor->name] = $descriptor;
        $this->byClass[$class] = $descriptor;

        return $descriptor;
    }

    private static function assertOwnedBy(Descriptor $descriptor, ?string $package): void
    {
        $owner = ActionName::package($descriptor->name);

        if ($owner === $package) {
            return;
        }

        throw new DefinitionException(match (true) {
            null === $package => \sprintf('The name "%s" of %s belongs to the package %s. Register it in that extension.', $descriptor->name, $descriptor->class, $owner),
            null === $owner => \sprintf('The name "%s" of %s needs the package name in front: "%s/%s".', $descriptor->name, $descriptor->class, $package, $descriptor->name),
            default => \sprintf('The name "%s" of %s must start with "%s/", the name of its package.', $descriptor->name, $descriptor->class, $package),
        });
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    public function get(string $name): Descriptor
    {
        return $this->byName[$name] ?? throw UnknownActionException::named($name);
    }

    /**
     * @param class-string $class
     */
    public function forClass(string $class): Descriptor
    {
        return $this->byClass[$class] ?? throw new DefinitionException(\sprintf('%s is not registered. Add it in your extension with $registry->action() or $registry->query().', $class));
    }

    /**
     * @return list<Descriptor> sorted by name
     */
    public function all(): array
    {
        $all = $this->byName;
        ksort($all);

        return array_values($all);
    }
}
