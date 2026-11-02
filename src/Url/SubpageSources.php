<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\DefinitionException;
use Psr\Container\ContainerInterface;

/**
 * The registered sources of subpages, by the type of their targets
 * (decision 0219).
 */
final class SubpageSources
{
    /** @var array<string, SubpageSource>|null */
    private ?array $sources = null;

    /**
     * @param list<class-string<SubpageSource>> $classes
     */
    public function __construct(
        private readonly array $classes,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @return array<string, SubpageSource>
     */
    public function all(): array
    {
        if (null === $this->sources) {
            $this->sources = [];

            foreach ($this->classes as $class) {
                $source = $this->container->get($class);

                if (!$source instanceof SubpageSource) {
                    throw new DefinitionException(\sprintf('%s must implement %s.', $class, SubpageSource::class));
                }

                $this->sources[$source->type()] = $source;
            }
        }

        return $this->sources;
    }

    public function get(string $type): ?SubpageSource
    {
        return $this->all()[$type] ?? null;
    }
}
