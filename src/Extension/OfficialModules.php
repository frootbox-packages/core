<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

/**
 * The catalog of official modules in resources/official-modules.json
 * (decision 0279). `composer reference` writes it from the manifests of
 * packages/modules/ in the monorepo, so it belongs to the version of
 * the core and needs no network.
 */
final class OfficialModules
{
    public const string FILE = __DIR__ . '/../../resources/official-modules.json';

    /**
     * @return list<OfficialModule> by name
     */
    public static function load(string $file = self::FILE): array
    {
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        $modules = [];

        foreach (\is_array($data) ? $data : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null) && \is_string($item['title'] ?? null) && \is_string($item['description'] ?? null)) {
                $modules[] = new OfficialModule($item['name'], $item['title'], $item['description']);
            }
        }

        return $modules;
    }

    /**
     * The modules below a folder, e.g. packages/modules/ of the monorepo:
     * every subfolder with composer.json and frootbox.yaml.
     *
     * @return list<OfficialModule> by name
     */
    public static function fromFolder(string $folder): array
    {
        $modules = [];

        $manifests = glob($folder . '/*/' . ExtensionPackage::MANIFEST);

        foreach (false === $manifests ? [] : $manifests as $manifest) {
            $path = \dirname($manifest);
            $composer = json_decode((string) file_get_contents($path . '/composer.json'), true);
            $name = \is_array($composer) && \is_string($composer['name'] ?? null) ? $composer['name'] : null;

            if (null === $name) {
                throw new ExtensionException(\sprintf('%s/composer.json has no name.', $path));
            }

            $package = ExtensionPackage::fromManifest($name, $path);
            $modules[$name] = new OfficialModule($name, $package->title, $package->description);
        }

        ksort($modules);

        return array_values($modules);
    }

    /**
     * @param list<OfficialModule> $modules
     */
    public static function encode(array $modules): string
    {
        return json_encode($modules, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }
}
