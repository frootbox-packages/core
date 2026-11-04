<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Frootbox\Core\Action\ActionName;
use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Frootbox;
use Frootbox\Core\Settings\SettingsClasses;
use Frootbox\Core\Theme\ThemeChain;

/**
 * The installed extensions, read only (decision 0274). Installing and
 * removing stay with Composer until the package service.
 *
 * @implements Query<ExtensionList>
 */
#[AsQuery('system.extension-list', 'Lists the installed extensions with version, theme, and the blocks, layouts, actions, queries and field types they bring')]
final readonly class ListExtensions implements Query
{
    public function handle(Extensions $extensions, Registry $registry, Catalog $catalog, Components $components, SettingsClasses $settings, ThemeChain $themes): ExtensionList
    {
        $packages = $extensions->packages;
        usort($packages, static fn(ExtensionPackage $a, ExtensionPackage $b): int => [$a->isProject, $a->name] <=> [$b->isProject, $b->name]);
        $descriptors = $catalog->all();
        $fieldTypes = array_keys($registry->fieldTypes());
        $settingKeys = array_keys($settings->all());

        $summaries = array_map(
            static fn(ExtensionPackage $package): ExtensionSummary => new ExtensionSummary(
                $package->name,
                $package->title,
                $package->description,
                $package->version,
                null !== $package->theme,
                $themes->contains($package->name),
                $package->isProject,
                self::components($components, ComponentKind::Block, $package->name),
                self::components($components, ComponentKind::Layout, $package->name),
                self::descriptors($descriptors, $package->name, true),
                self::descriptors($descriptors, $package->name, false),
                self::sorted(array_filter($fieldTypes, static fn(string $name): bool => ActionName::package($name) === $package->name)),
                array_any($settingKeys, static fn(string $key): bool => $key === $package->name || str_starts_with($key, $package->name . '.')),
                is_dir($package->path . '/migrations'),
            ),
            $packages,
        );

        return new ExtensionList(Frootbox::version(), $summaries);
    }

    /**
     * @return list<string>
     */
    private static function components(Components $components, ComponentKind $kind, string $package): array
    {
        return self::sorted(array_map(
            static fn(Component $component): string => $component->name,
            array_filter($components->all($kind), static fn(Component $component): bool => $component->package() === $package),
        ));
    }

    /**
     * @param iterable<Descriptor> $descriptors
     *
     * @return list<string>
     */
    private static function descriptors(iterable $descriptors, string $package, bool $actions): array
    {
        $names = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->isAction() === $actions && ActionName::package($descriptor->name) === $package) {
                $names[] = $descriptor->name;
            }
        }

        return self::sorted($names);
    }

    /**
     * @param array<array-key, string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $names = array_values($names);
        sort($names);

        return $names;
    }
}
