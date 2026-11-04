<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\Registry;
use Psr\Container\ContainerInterface;

/**
 * The consent tools of all extensions and the one that takes over
 * (decision 0264): the only ready one, or the one of the setting "tool"
 * when there are several; "none" leaves it to the core.
 */
final class ConsentTools
{
    public const string NONE = 'none';

    /** @var array<string, ConsentTool>|null */
    private ?array $tools = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly ContainerInterface $container,
        private readonly ConsentSettings $settings,
    ) {}

    /**
     * @return array<string, ConsentTool> by name, in the order of registration
     */
    public function all(): array
    {
        if (null !== $this->tools) {
            return $this->tools;
        }

        $tools = [];

        foreach ($this->registry->items(ConsentTool::class) as $tool) {
            if (isset($tools[$tool->name])) {
                throw new ExtensionException(\sprintf('The consent tool "%s" is registered twice. A name belongs to one extension only.', $tool->name));
            }

            $tools[$tool->name] = $tool;
        }

        return $this->tools = $tools;
    }

    public function active(): ?ConsentTool
    {
        $chosen = trim($this->settings->tool);

        if (self::NONE === $chosen) {
            return null;
        }

        $ready = array_filter($this->all(), fn(ConsentTool $tool): bool => $this->provider($tool)->ready());

        if ('' !== $chosen) {
            return $ready[$chosen] ?? null;
        }

        return 1 === \count($ready) ? reset($ready) : null;
    }

    public function provider(ConsentTool $tool): ConsentToolProvider
    {
        $provider = $this->container->get($tool->provider);

        return $provider instanceof ConsentToolProvider ? $provider : throw new ExtensionException(\sprintf('The consent tool "%s" needs a class that implements %s, %s does not.', $tool->name, ConsentToolProvider::class, $tool->provider));
    }
}
