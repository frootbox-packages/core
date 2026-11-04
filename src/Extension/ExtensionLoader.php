<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Frootbox\Core\Cache\CacheFile;
use Frootbox\Core\Extension\Source\ExtensionSource;
use Frootbox\Core\ProjectPaths;

/**
 * Finds the installed extensions and lets them register themselves.
 *
 * In production the list of extensions is cached in var/cache and read
 * again only after composer install or update. In development it is read
 * on every request, so a changed frootbox.yaml shows up immediately.
 */
final class ExtensionLoader
{
    public const string CACHE_FILE = 'cache/extensions.cache';

    /**
     * @param list<ExtensionSource> $sources
     */
    public static function discover(array $sources, ProjectPaths $paths, bool $useCache): Extensions
    {
        $fingerprint = implode('|', array_map(static fn(ExtensionSource $source): string => $source::class . ':' . $source->fingerprint(), $sources));
        $cacheFile = $paths->var(self::CACHE_FILE);

        if ($useCache) {
            $cached = CacheFile::read($cacheFile, $fingerprint, [Extensions::class, ExtensionPackage::class]);

            if ($cached instanceof Extensions) {
                return $cached;
            }
        }

        $packages = [];

        foreach ($sources as $source) {
            foreach ($source->packages() as $package) {
                if (isset($packages[$package->name])) {
                    throw new ExtensionException(\sprintf('The extension %s was found twice.', $package->name));
                }

                $packages[$package->name] = $package;
            }
        }

        $extensions = new Extensions(self::sort($packages), $fingerprint);

        if ($useCache) {
            CacheFile::write($cacheFile, $fingerprint, $extensions);
        }

        return $extensions;
    }

    /**
     * Runs CoreExtension and then every extension in load order.
     */
    public static function boot(Extensions $extensions): Registry
    {
        $registry = new Registry();
        new CoreExtension()->register($registry);

        foreach ($extensions as $package) {
            self::registerMigrations($package, $registry);

            if (null !== $package->extensionClass) {
                $registry->forPackage($package->name, static fn() => self::instantiate($package)->register($registry), $package->path);
            }
        }

        return $registry;
    }

    /**
     * The packages in load order, e.g. for a check that boots another set
     * than the installed one (decision 0278).
     *
     * @param list<ExtensionPackage> $packages
     */
    public static function arrange(array $packages, string $fingerprint = ''): Extensions
    {
        $byName = [];

        foreach ($packages as $package) {
            $byName[$package->name] = $package;
        }

        return new Extensions(self::sort($byName), $fingerprint);
    }

    /**
     * Dependencies first. Among packages that are ready at the same time,
     * other packages come before project extensions, then by name.
     *
     * @param array<string, ExtensionPackage> $pending
     *
     * @return list<ExtensionPackage>
     */
    private static function sort(array $pending): array
    {
        $sorted = [];

        while ([] !== $pending) {
            $ready = array_filter(
                $pending,
                static fn(ExtensionPackage $package): bool => [] === array_intersect($package->requires, array_keys($pending)),
            );

            if ([] === $ready) {
                throw new ExtensionException(\sprintf('The extensions %s require each other in a circle.', implode(', ', array_keys($pending))));
            }

            usort($ready, static fn(ExtensionPackage $a, ExtensionPackage $b): int => [$a->isProject, $a->name] <=> [$b->isProject, $b->name]);
            $next = $ready[0];
            $sorted[] = $next;
            unset($pending[$next->name]);
        }

        return $sorted;
    }

    private static function registerMigrations(ExtensionPackage $package, Registry $registry): void
    {
        $directory = $package->path . '/migrations';

        if (!is_dir($directory)) {
            return;
        }

        $namespace = $package->migrationsNamespace();

        if (null === $namespace) {
            throw new ExtensionException(\sprintf(
                '%s has a folder migrations/ but no namespace for it. Add it to autoload.psr-4 in its composer.json, e.g. "Acme\\\\Website\\\\Migrations\\\\": "migrations/", and run composer dump-autoload.',
                $package->name,
            ));
        }

        $registry->migrations($namespace, $directory, $package->name);
    }

    private static function instantiate(ExtensionPackage $package): Extension
    {
        $class = (string) $package->extensionClass;

        if (!class_exists($class)) {
            throw new ExtensionException(\sprintf(
                'The class %s from %s of %s does not exist. Check autoload.psr-4 in its composer.json and run composer dump-autoload.',
                $class,
                ExtensionPackage::MANIFEST,
                $package->name,
            ));
        }

        if (!is_subclass_of($class, Extension::class)) {
            throw new ExtensionException(\sprintf('The class %s of %s must implement %s.', $class, $package->name, Extension::class));
        }

        return new $class();
    }
}
