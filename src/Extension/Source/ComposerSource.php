<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Source;

use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\ExtensionPackage;

/**
 * Finds extensions among the Composer packages of an installation: every
 * installed package with a frootbox.yaml is one.
 *
 * Packages below <project>/extensions/ count as project extensions.
 */
final readonly class ComposerSource implements ExtensionSource
{
    public function __construct(
        private string $vendorDirectory,
        private string $projectExtensionsDirectory,
    ) {}

    public function packages(): array
    {
        $packages = [];
        $projectDirectory = realpath($this->projectExtensionsDirectory);

        foreach ($this->installed() as $package) {
            $path = $this->installPath($package);

            if (null === $path || !is_file($path . '/' . ExtensionPackage::MANIFEST)) {
                continue;
            }

            $name = \is_string($package['name'] ?? null) ? $package['name'] : '';
            $require = \is_array($package['require'] ?? null) ? $package['require'] : [];

            $packages[] = ExtensionPackage::fromManifest(
                $name,
                $path,
                array_map(strval(...), array_keys($require)),
                $this->psr4($package),
                false !== $projectDirectory && str_starts_with($path . '/', $projectDirectory . '/'),
            );
        }

        return $packages;
    }

    public function fingerprint(): string
    {
        $file = $this->installedFile();

        return is_file($file) ? (string) filemtime($file) . '-' . (string) filesize($file) : 'none';
    }

    /**
     * @return list<array<mixed>>
     */
    private function installed(): array
    {
        $file = $this->installedFile();

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        // Composer 1 wrote a plain list, Composer 2 wraps it in "packages".
        $packages = \is_array($data) ? ($data['packages'] ?? $data) : null;

        if (!\is_array($packages)) {
            throw new ExtensionException(\sprintf('%s cannot be read. Run composer install again.', $file));
        }

        return array_values(array_filter($packages, \is_array(...)));
    }

    /**
     * @param array<mixed> $package
     */
    private function installPath(array $package): ?string
    {
        $path = $package['install-path'] ?? null;

        if (!\is_string($path)) {
            return null;
        }

        // Composer stores the path relative to vendor/composer/.
        $absolute = str_starts_with($path, '/') ? $path : $this->vendorDirectory . '/composer/' . $path;
        $real = realpath($absolute);

        return false === $real ? null : $real;
    }

    /**
     * @param array<mixed> $package
     *
     * @return array<string, list<string>>
     */
    private function psr4(array $package): array
    {
        $autoload = \is_array($package['autoload'] ?? null) ? $package['autoload'] : [];
        $psr4 = \is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
        $result = [];

        foreach ($psr4 as $prefix => $directories) {
            $result[(string) $prefix] = array_values(array_filter((array) $directories, \is_string(...)));
        }

        return $result;
    }

    private function installedFile(): string
    {
        return $this->vendorDirectory . '/composer/installed.json';
    }
}
