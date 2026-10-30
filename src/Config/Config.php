<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Config;

/**
 * Configuration of an installation, read from config/app.yaml and the environment.
 */
final readonly class Config
{
    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $env
     */
    public function __construct(
        public Environment $environment,
        private array $values = [],
        private array $env = [],
    ) {}

    /**
     * Returns a value by its dot-separated path, e.g. "app.name".
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $current = $this->values;

        foreach (explode('.', $path) as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return $default;
            }

            $current = $current[$key];
        }

        return $current;
    }

    public function string(string $path, ?string $default = null): string
    {
        $value = $this->get($path, $default);

        if (!\is_string($value)) {
            throw new ConfigException(\sprintf('Config value "%s" must be a string.', $path));
        }

        return $value;
    }

    public function bool(string $path, ?bool $default = null): bool
    {
        $value = $this->get($path, $default);

        if (!\is_bool($value)) {
            throw new ConfigException(\sprintf('Config value "%s" must be a boolean.', $path));
        }

        return $value;
    }

    /**
     * Returns an environment variable from the server or the .env file.
     */
    public function env(string $name): ?string
    {
        return $this->env[$name] ?? null;
    }
}
