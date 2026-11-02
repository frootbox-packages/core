<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Config;

use Frootbox\Core\ProjectPaths;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the configuration from config/app.yaml, the .env file and the server environment.
 */
final class ConfigLoader
{
    private const string ENV_PLACEHOLDER = '/%env\(([A-Z0-9_]+)\)%/';

    /**
     * @param array<string, string> $serverEnvironment Real environment variables. They take precedence over .env.
     */
    public static function load(ProjectPaths $paths, array $serverEnvironment = []): Config
    {
        $env = [...self::readDotenv($paths->root . '/.env'), ...$serverEnvironment];

        $environment = Environment::tryFrom($env['APP_ENV'] ?? Environment::Production->value)
            ?? throw new ConfigException('APP_ENV must be "dev" or "prod".');

        $values = self::readYaml($paths->config('app.yaml'));
        $extensions = self::readExtensions($paths);

        if ([] !== $extensions) {
            $values['extensions'] = [...(\is_array($values['extensions'] ?? null) ? $values['extensions'] : []), ...$extensions];
        }

        $values = self::resolvePlaceholders($values, $env);

        return new Config($environment, $values, $env);
    }

    /**
     * @return array<string, string>
     */
    private static function readDotenv(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);

        if (false === $contents) {
            throw new ConfigException(\sprintf('Could not read "%s".', $file));
        }

        $variables = [];

        foreach (new Dotenv()->parse($contents, $file) as $name => $value) {
            if (!\is_string($value)) {
                throw new ConfigException(\sprintf('Variable "%s" in "%s" must be a string.', $name, $file));
            }

            $variables[(string) $name] = $value;
        }

        return $variables;
    }

    /**
     * The settings of extensions in config/extensions/<vendor>/<package>.yaml
     * (decisions 0093, 0221), by package.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function readExtensions(ProjectPaths $paths): array
    {
        $extensions = [];

        $files = glob($paths->config('extensions/*/*.yaml'));

        foreach (false === $files ? [] : $files as $file) {
            $extensions[basename(\dirname($file)) . '/' . basename($file, '.yaml')] = self::readYaml($file);
        }

        return $extensions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readYaml(string $file): array
    {
        if (!is_file($file)) {
            throw new ConfigException(\sprintf('Configuration file "%s" is missing.', $file));
        }

        try {
            $values = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new ConfigException(\sprintf('Configuration file "%s" is invalid: %s', $file, $exception->getMessage()), 0, $exception);
        }

        if (null === $values) {
            return [];
        }

        if (!\is_array($values) || array_is_list($values) && [] !== $values) {
            throw new ConfigException(\sprintf('Configuration file "%s" must contain a map of keys.', $file));
        }

        $result = [];

        foreach ($values as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * Replaces %env(NAME)% in all string values.
     *
     * @param array<array-key, mixed> $values
     * @param array<string, string>   $env
     *
     * @return array<string, mixed>
     */
    private static function resolvePlaceholders(array $values, array $env): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $result[(string) $key] = match (true) {
                \is_array($value) => self::resolvePlaceholders($value, $env),
                \is_string($value) => self::resolveString($value, $env),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * @param array<string, string> $env
     */
    private static function resolveString(string $value, array $env): string
    {
        return preg_replace_callback(
            self::ENV_PLACEHOLDER,
            static fn(array $match): string => $env[$match[1]]
                ?? throw new ConfigException(\sprintf('Environment variable "%s" is not set.', $match[1])),
            $value,
        ) ?? throw new ConfigException(\sprintf('Could not resolve "%s".', $value));
    }
}
