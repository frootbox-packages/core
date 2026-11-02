<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Schedule;

use Frootbox\Core\Action\DefinitionException;
use Psr\Container\ContainerInterface;

/**
 * The registered scheduled types of plugins, by type (decision 0219).
 */
final class ScheduledTypes
{
    /** @var array<string, ScheduledType>|null */
    private ?array $types = null;

    /**
     * @param list<class-string<ScheduledType>> $classes
     */
    public function __construct(
        private readonly array $classes,
        private readonly ContainerInterface $container,
    ) {}

    public function get(string $type): ?ScheduledType
    {
        if (null === $this->types) {
            $this->types = [];

            foreach ($this->classes as $class) {
                $scheduled = $this->container->get($class);

                if (!$scheduled instanceof ScheduledType) {
                    throw new DefinitionException(\sprintf('%s must implement %s.', $class, ScheduledType::class));
                }

                $this->types[$scheduled->type()] = $scheduled;
            }
        }

        return $this->types[$type] ?? null;
    }
}
