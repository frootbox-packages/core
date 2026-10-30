<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
     * @param class-string $class
     */
    public function add(string $class): Descriptor
    {
        $descriptor = Descriptor::of($class);
        $existing = $this->byName[$descriptor->name] ?? null;

        if (null !== $existing) {
            throw new DefinitionException(\sprintf('The name "%s" is registered twice: %s and %s.', $descriptor->name, $existing->class, $class));
        }

        $this->byName[$descriptor->name] = $descriptor;
        $this->byClass[$class] = $descriptor;

        return $descriptor;
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
