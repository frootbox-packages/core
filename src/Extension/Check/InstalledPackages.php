<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Check;

use Composer\Autoload\ClassLoader;

/**
 * The Composer packages of this installation with version, license and
 * requirements, read from vendor/composer/installed.json of every
 * registered autoloader, so also from the shared vendor/ of the
 * workbench. No network (decision 0278).
 */
final readonly class InstalledPackages
{
    /**
     * @param array<string, array{version: string|null, license: list<string>, require: list<string>}> $packages
     */
    public function __construct(private array $packages = []) {}

    public static function fromAutoloaders(): self
    {
        $packages = [];

        foreach (array_keys(ClassLoader::getRegisteredLoaders()) as $vendor) {
            $packages = [...self::read($vendor . '/composer/installed.json'), ...$packages];
        }

        return new self($packages);
    }

    /**
     * The license of one package, null when it is not installed.
     *
     * @return list<string>|null
     */
    public function license(string $name): ?array
    {
        return $this->packages[$name]['license'] ?? null;
    }

    /**
     * The packages behind these requirements and their requirements in
     * turn, sorted by name. Platform requirements (php, ext-…) do not
     * count; below frootbox/* it does not descend, the packages of
     * Frootbox are checked on their own.
     *
     * @param list<string> $require
     *
     * @return list<Dependency>
     */
    public function dependencies(array $require): array
    {
        $found = [];
        $queue = $require;

        while ([] !== $queue) {
            $name = array_shift($queue);

            if (!str_contains($name, '/') || isset($found[$name])) {
                continue;
            }

            $package = $this->packages[$name] ?? null;

            if (null === $package) {
                $found[$name] = new Dependency($name, false);

                continue;
            }

            $found[$name] = new Dependency($name, true, $package['version'], $package['license']);

            if (!str_starts_with($name, 'frootbox/')) {
                array_push($queue, ...$package['require']);
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * @return array<string, array{version: string|null, license: list<string>, require: list<string>}>
     */
    private static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        $list = \is_array($data) ? ($data['packages'] ?? $data) : [];
        $packages = [];

        foreach (\is_array($list) ? $list : [] as $package) {
            if (!\is_array($package) || !\is_string($package['name'] ?? null)) {
                continue;
            }

            $version = $package['pretty_version'] ?? $package['version'] ?? null;
            $require = \is_array($package['require'] ?? null) ? $package['require'] : [];

            $packages[$package['name']] = [
                'version' => \is_string($version) ? $version : null,
                'license' => self::licenses($package['license'] ?? null),
                'require' => array_map(strval(...), array_keys($require)),
            ];
        }

        return $packages;
    }

    /**
     * @return list<string>
     */
    public static function licenses(mixed $license): array
    {
        if (\is_string($license)) {
            return '' === trim($license) ? [] : [trim($license)];
        }

        return \is_array($license) ? array_values(array_filter(array_map(static fn(mixed $item): string => \is_string($item) ? trim($item) : '', $license), static fn(string $item): bool => '' !== $item)) : [];
    }
}
