<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use DI\Container;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Container\ContainerFactory;
use Frootbox\Core\Extension\ExtensionLoader;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\ProjectPaths;

/**
 * The extensions in Fixtures/Components: themes acme/base and acme/site
 * (child of base), the module acme/news and the theme acme/other.
 */
final class FixtureSite
{
    public const string PATH = __DIR__ . '/../Fixtures/Components';

    public static function extensions(string ...$names): Extensions
    {
        $names = [] === $names ? ['base', 'site', 'news', 'other'] : $names;

        return new Extensions(
            array_values(array_map(static fn(string $name): ExtensionPackage => ExtensionPackage::fromManifest('acme/' . $name, self::path($name)), $names)),
            'fixtures',
        );
    }

    public static function path(string $name): string
    {
        return (string) realpath(self::PATH . '/' . $name);
    }

    public static function container(TemporaryProject $project, ?string $theme = 'acme/site', Environment $environment = Environment::Development, ?Extensions $extensions = null): Container
    {
        $extensions ??= self::extensions();
        $config = new Config($environment, null === $theme ? [] : ['theme' => $theme]);

        return ContainerFactory::create($config, new ProjectPaths($project->root), $extensions, ExtensionLoader::boot($extensions));
    }
}
