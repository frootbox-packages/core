<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Cache\CacheFile;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Frootbox;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Theme\ThemeChain;

/**
 * Loads the blocks and layouts. In production they are cached in
 * var/cache until packages change or the active theme does. After editing
 * blocks on a live server, run bin/frootbox cache:clear.
 */
final class ComponentsFactory
{
    public const string CACHE_FILE = 'cache/components.cache';

    private const array CLASSES = [Components::class, Component::class, ComponentKind::class, Variant::class, Option::class, Area::class, Field::class];

    public static function create(ComponentLoader $loader, Extensions $extensions, ThemeChain $themes, Environment $environment, ProjectPaths $paths): Components
    {
        if ($environment->isDevelopment()) {
            return $loader->load();
        }

        $file = $paths->var(self::CACHE_FILE);
        $fingerprint = implode('|', [Frootbox::VERSION, $extensions->fingerprint, $themes->active()->name ?? '']);
        $cached = CacheFile::read($file, $fingerprint, self::CLASSES);

        if ($cached instanceof Components) {
            return $cached;
        }

        $components = $loader->load();
        CacheFile::write($file, $fingerprint, $components);

        return $components;
    }
}
