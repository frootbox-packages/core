<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Extension\Registry;
use Psr\Container\ContainerInterface;

/**
 * The active providers from all sources (decision 0322).
 */
final readonly class Providers
{
    public function __construct(
        private Registry $registry,
        private ContainerInterface $container,
    ) {}

    /**
     * @return list<Provider>
     */
    public function all(): array
    {
        $providers = [];

        foreach ($this->registry->items(OidcProviders::class) as $item) {
            $source = $this->container->get($item->source);

            if ($source instanceof ProviderSource) {
                foreach ($source->providers() as $provider) {
                    $providers[$provider->key] ??= $provider;
                }
            }
        }

        return array_values($providers);
    }

    public function find(string $key): ?Provider
    {
        foreach ($this->all() as $provider) {
            if ($provider->key === $key) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @throws OidcException if the provider is not active
     */
    public function get(string $key): Provider
    {
        return $this->find($key) ?? throw OidcException::unknownProvider($key);
    }
}
