<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

use Frootbox\Core\Action\DefinitionException;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * The registered sources of the global search (decision 0214), each
 * created through the container when it is needed.
 */
final class SearchSources
{
    /** @var array<string, AsSearchSource> by class */
    private array $attributes = [];

    /**
     * @param list<class-string<SearchSource>> $classes
     */
    public function __construct(
        private readonly array $classes,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * The name and title of each source, by name.
     *
     * @return array<string, AsSearchSource>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->classes as $class) {
            $attribute = $this->attribute($class);
            $all[$attribute->name] = $attribute;
        }

        return $all;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): SearchSource
    {
        foreach ($this->classes as $class) {
            if ($this->attribute($class)->name === $name) {
                $source = $this->container->get($class);

                return $source instanceof SearchSource ? $source : throw new DefinitionException(\sprintf('%s must implement %s.', $class, SearchSource::class));
            }
        }

        throw new DefinitionException(\sprintf('There is no search source "%s".', $name));
    }

    /**
     * @param class-string<SearchSource> $class
     */
    public static function nameOf(string $class): string
    {
        return self::read($class)->name;
    }

    /**
     * @param class-string<SearchSource> $class
     */
    private function attribute(string $class): AsSearchSource
    {
        return $this->attributes[$class] ??= self::read($class);
    }

    /**
     * @param class-string<SearchSource> $class
     */
    private static function read(string $class): AsSearchSource
    {
        $attributes = new ReflectionClass($class)->getAttributes(AsSearchSource::class);

        return [] === $attributes ? throw new DefinitionException(\sprintf('%s needs #[AsSearchSource].', $class)) : $attributes[0]->newInstance();
    }
}
