<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\Registry;

/**
 * The services of all extensions by name (decision 0224).
 */
final class ExternalServices
{
    /** @var array<string, ExternalService>|null */
    private ?array $services = null;

    public function __construct(private readonly Registry $registry) {}

    public function get(string $name): ?ExternalService
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return array<string, ExternalService> by name, in the order of registration
     */
    public function all(): array
    {
        if (null !== $this->services) {
            return $this->services;
        }

        $services = [];

        foreach ($this->registry->items(ExternalService::class) as $service) {
            if (isset($services[$service->name])) {
                throw new ExtensionException(\sprintf('The external service "%s" is registered twice. A name belongs to one extension only.', $service->name));
            }

            $services[$service->name] = $service;
        }

        return $this->services = $services;
    }
}
