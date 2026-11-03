<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\DefinitionException;
use Psr\Container\ContainerInterface;

/**
 * The registered plugins, by the type of their objects (decision 0232).
 */
final class Plugins
{
    /** @var array<string, Plugin>|null */
    private ?array $plugins = null;

    /**
     * @param list<class-string<Plugin>> $classes
     */
    public function __construct(
        private readonly array $classes,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @return array<string, Plugin>
     */
    public function all(): array
    {
        if (null === $this->plugins) {
            $this->plugins = [];

            foreach ($this->classes as $class) {
                $plugin = $this->container->get($class);

                if (!$plugin instanceof Plugin) {
                    throw new DefinitionException(\sprintf('%s must implement %s.', $class, Plugin::class));
                }

                $this->plugins[$plugin->type()] = $plugin;
            }
        }

        return $this->plugins;
    }

    public function get(string $type): ?Plugin
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * The plugin whose view is this block, e.g. for the block itself.
     */
    public function ofBlock(string $block): ?Plugin
    {
        foreach ($this->all() as $plugin) {
            if ($plugin->block() === $block) {
                return $plugin;
            }
        }

        return null;
    }
}
