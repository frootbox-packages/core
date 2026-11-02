<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One installed extension: a package with frootbox.yaml.
 */
final readonly class ExtensionPackage
{
    public const string MANIFEST = 'frootbox.yaml';

    private const array KEYS = ['title', 'description', 'extension', 'theme'];

    /**
     * @param list<string>                $requires Names of required packages
     * @param array<string, list<string>> $psr4     Namespace prefix => directories, relative to $path
     * @param array<mixed>|null           $theme    Theme settings, used from M2 on
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $title,
        public string $description = '',
        public ?string $extensionClass = null,
        public array $requires = [],
        public array $psr4 = [],
        public bool $isProject = false,
        public ?array $theme = null,
    ) {}

    /**
     * @param list<string>                $requires
     * @param array<string, list<string>> $psr4
     */
    public static function fromManifest(string $name, string $path, array $requires = [], array $psr4 = [], bool $isProject = false): self
    {
        $file = $path . '/' . self::MANIFEST;

        try {
            $manifest = Yaml::parseFile($file) ?? [];
        } catch (ParseException $exception) {
            throw new ExtensionException(\sprintf('%s of %s is not valid YAML: %s', self::MANIFEST, $name, $exception->getMessage()), previous: $exception);
        }

        if (!\is_array($manifest)) {
            throw new ExtensionException(\sprintf('%s of %s must contain key-value pairs.', self::MANIFEST, $name));
        }

        $unknown = array_diff(array_map(strval(...), array_keys($manifest)), self::KEYS);

        if ([] !== $unknown) {
            throw new ExtensionException(\sprintf('%s of %s contains unknown keys: %s. Allowed are %s.', self::MANIFEST, $name, implode(', ', $unknown), implode(', ', self::KEYS)));
        }

        $theme = $manifest['theme'] ?? null;

        if (null !== $theme && !\is_array($theme)) {
            throw new ExtensionException(\sprintf('"theme" in %s of %s must contain key-value pairs.', self::MANIFEST, $name));
        }

        return new self(
            $name,
            $path,
            self::string($manifest, 'title', $name) ?? $name,
            self::string($manifest, 'description', $name) ?? '',
            self::string($manifest, 'extension', $name),
            $requires,
            $psr4,
            $isProject,
            $theme,
        );
    }

    /**
     * The namespace that composer.json maps to the folder migrations/, if any.
     */
    public function migrationsNamespace(): ?string
    {
        foreach ($this->psr4 as $prefix => $directories) {
            foreach ($directories as $directory) {
                if ('migrations' === trim($directory, '/')) {
                    return trim($prefix, '\\');
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $manifest
     */
    private static function string(array $manifest, string $key, string $name): ?string
    {
        $value = $manifest[$key] ?? null;

        if (null !== $value && !\is_string($value)) {
            throw new ExtensionException(\sprintf('"%s" in %s of %s must be text.', $key, self::MANIFEST, $name));
        }

        return $value;
    }
}
