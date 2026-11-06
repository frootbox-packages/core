<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Settings\Settings;
use Psr\Container\ContainerInterface;

/**
 * The AI providers of all extensions and the one that answers
 * (decision 0294).
 */
final class Providers
{
    /** @var array<string, Provider>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly ContainerInterface $container,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array<string, Provider> by name, in the order of registration
     */
    public function all(): array
    {
        if (null !== $this->providers) {
            return $this->providers;
        }

        $providers = [];

        foreach ($this->registry->items(Provider::class) as $provider) {
            if (isset($providers[$provider->name])) {
                throw new ExtensionException(\sprintf('The AI provider "%s" is registered twice. A name belongs to one extension only.', $provider->name));
            }

            $providers[$provider->name] = $provider;
        }

        return $this->providers = $providers;
    }

    /**
     * The provider of the settings, else the first one; null without any.
     */
    public function active(): ?Provider
    {
        $all = $this->all();

        return $all[$this->settings->get(AiSettings::class)->provider] ?? array_first($all);
    }

    public function client(Provider $provider): ProviderClient
    {
        $client = $this->container->get($provider->client);

        if (!$client instanceof ProviderClient) {
            throw new ExtensionException(\sprintf('The client of the AI provider "%s" must implement %s.', $provider->name, ProviderClient::class));
        }

        return $client;
    }
}
