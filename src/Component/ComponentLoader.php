<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use FilesystemIterator;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Theme\ThemeChain;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Finds the blocks and layouts of the extensions and resolves overrides
 * through the theme chain.
 *
 *     <package>/blocks/<Name>/block.yaml               shared schema, optional
 *     <package>/blocks/<Name>/views/<Variant>.twig     one file per variant, Default is required
 *     <package>/blocks/<Name>/assets/…                 styles and scripts
 *     <package>/blocks/<Name>/preview.svg              preview in the picker, also preview.<Variant>.webp
 *     <theme>/overrides/<vendor>/<package>/blocks/<Name>/…   replaces single files
 *
 * Blocks of themes outside the active chain are not available.
 */
final readonly class ComponentLoader
{
    public function __construct(
        private Extensions $extensions,
        private ThemeChain $themes,
        private DefinitionReader $reader,
    ) {}

    /**
     * @throws ComponentException With all errors found
     */
    public function load(): Components
    {
        $errors = new DefinitionErrors();
        $found = [];

        foreach (ComponentKind::cases() as $kind) {
            $found[$kind->value] = [];

            foreach ($this->available() as $package) {
                foreach ($this->folders($package->path . '/' . $kind->folder()) as $name => $path) {
                    $where = $package->name . ': ' . $kind->folder() . '/' . $name;

                    if (!ComponentName::isLocal($name)) {
                        $errors->add($where, '', 'folder names are PascalCase, e.g. "ImageGallery"');

                        continue;
                    }

                    $component = $this->component($kind, $package, $name, $path, $errors);

                    if (null !== $component) {
                        $found[$kind->value][$component->name] = $component;
                    }
                }
            }
        }

        $this->checkOverrides($found, $errors);

        if ($errors->count() > 0) {
            throw new ComponentException($errors->all());
        }

        return new Components($found[ComponentKind::Block->value], $found[ComponentKind::Layout->value]);
    }

    /**
     * Extensions that are not themes, plus the themes of the active chain.
     *
     * @return list<ExtensionPackage>
     */
    private function available(): array
    {
        return array_values(array_filter(
            $this->extensions->packages,
            fn(ExtensionPackage $package): bool => null === $package->theme || $this->themes->contains($package->name),
        ));
    }

    private function component(ComponentKind $kind, ExtensionPackage $package, string $name, string $path, DefinitionErrors $errors): ?Component
    {
        $fullName = $package->name . '/' . $name;
        $relative = $kind->folder() . '/' . $name;
        $where = $package->name . ': ' . $relative;
        $overrides = $this->overrideFolders($package->name, $relative);

        $views = $this->views($path, $where, $errors);

        if (!isset($views[Variant::DEFAULT])) {
            $errors->add($where, '', 'views/Default.twig is missing. Every ' . $kind->value . ' has a variant Default.');

            return null;
        }

        // Overriding files win, the most specific theme first.
        $templates = [];

        foreach ([...$overrides, [$package->name, $path]] as [$from, $folder]) {
            foreach ($folder === $path ? $views : $this->views($folder, $from . ': overrides/' . $package->name . '/' . $relative, $errors) as $variant => $file) {
                $templates[$variant] ??= [$from, $file];
            }
        }

        $schemaFile = $path . '/' . $kind->schemaFile();
        $hasSchemaFile = is_file($schemaFile);
        $schema = $hasSchemaFile
            ? $this->reader->read($this->yaml($schemaFile, $where . '/' . $kind->schemaFile(), $errors), DefinitionReader::SCHEMA_KEYS, true, $package->name, $where . '/' . $kind->schemaFile(), $errors)
            : null;

        $variants = [];
        $title = $schema->title ?? $name;
        $description = $schema->description ?? '';
        $fields = $schema->fields ?? [];
        $areas = $schema->areas ?? [];
        $widget = $schema->widget ?? false;
        $group = $schema->group ?? null;
        $keywords = $schema->keywords ?? [];

        // Default first, then the others by name.
        uksort($templates, static fn(string $a, string $b): int => [Variant::DEFAULT !== $a, $a] <=> [Variant::DEFAULT !== $b, $b]);

        foreach ($templates as $variant => [$from, $file]) {
            $fileWhere = ($from === $package->name ? $where : $from . ': overrides/' . $package->name . '/' . $relative) . '/views/' . $variant . '.twig';
            $holdsSchema = !$hasSchemaFile && $from === $package->name && Variant::DEFAULT === $variant;
            $header = $this->header($file, $fileWhere, $errors);

            if (null === $header) {
                // Reported already; without its header a view holding the schema is of no use.
                if ($holdsSchema) {
                    return null;
                }

                $header = [];
            }

            if (!$holdsSchema && !$hasSchemaFile && Variant::DEFAULT === $variant) {
                // An overriding Default view: the schema still comes from the original.
                $original = $this->reader->read($this->header($views[Variant::DEFAULT], $where . '/views/Default.twig', $errors) ?? [], DefinitionReader::FULL_KEYS, true, $package->name, $where . '/views/Default.twig', $errors);
                [$title, $description, $fields, $areas, $widget, $group, $keywords] = [$original->title ?? $name, $original->description ?? '', $original->fields, $original->areas, $original->widget, $original->group, $original->keywords];
            }

            $definition = $this->reader->read(
                $header,
                $holdsSchema ? DefinitionReader::FULL_KEYS : DefinitionReader::VARIANT_KEYS,
                $holdsSchema,
                $package->name,
                $fileWhere,
                $errors,
            );

            if ($holdsSchema) {
                [$title, $description, $fields, $areas, $widget, $group, $keywords] = [$definition->title ?? $name, $definition->description ?? '', $definition->fields, $definition->areas, $definition->widget, $definition->group, $definition->keywords];
            }

            $variants[$variant] = new Variant(
                $variant,
                $definition->title ?? (Variant::DEFAULT === $variant ? $title : $variant),
                $definition->description ?? (Variant::DEFAULT === $variant ? $description : ''),
                $definition->options,
                $file,
                $from,
            );
        }

        if ($widget && ComponentKind::Layout === $kind) {
            $errors->add($where, 'widget', 'Only blocks can be widgets.');
            $widget = false;
        }

        return new Component($kind, $fullName, $path, $title, $description, $fields, $areas, $variants, $this->assets($path, $overrides), $widget, $group, $keywords, $this->previews($path, $overrides));
    }

    /**
     * Preview files by variant; a theme replaces the preview of a variant
     * with its own, whatever the format (decision 0148).
     *
     * @param list<array{string, string}> $overrides
     *
     * @return array<string, string>
     */
    private function previews(string $path, array $overrides): array
    {
        $previews = Previews::in($path);

        foreach (array_reverse(array_column($overrides, 1)) as $folder) {
            $previews = [...$previews, ...Previews::in($folder)];
        }

        uksort($previews, static fn(string $a, string $b): int => [Variant::DEFAULT !== $a, $a] <=> [Variant::DEFAULT !== $b, $b]);

        return $previews;
    }

    /**
     * Folders overriding a block or layout of a package, most specific theme first.
     *
     * @return list<array{string, string}> theme name, folder
     */
    private function overrideFolders(string $package, string $relative): array
    {
        $folders = [];

        foreach ($this->themes->themes as $theme) {
            $folder = $theme->path . '/overrides/' . $package . '/' . $relative;

            if ($theme->name !== $package && is_dir($folder)) {
                $folders[] = [$theme->name, $folder];
            }
        }

        return $folders;
    }

    /**
     * The views of a folder: variant name => file.
     *
     * @return array<string, string>
     */
    private function views(string $path, string $where, DefinitionErrors $errors): array
    {
        $views = [];
        $files = glob($path . '/views/*.twig');

        foreach (false === $files ? [] : $files as $file) {
            $variant = basename($file, '.twig');

            if (!ComponentName::isLocal($variant)) {
                $errors->add($where . '/views/' . basename($file), '', 'variant names are PascalCase, e.g. ImageLeft.twig');

                continue;
            }

            $views[$variant] = $file;
        }

        return $views;
    }

    /**
     * Files in assets/, overriding files replacing those of the same path.
     *
     * @param list<array{string, string}> $overrides
     *
     * @return array<string, string>
     */
    private function assets(string $path, array $overrides): array
    {
        $assets = [];

        foreach ([$path, ...array_column(array_reverse($overrides), 1)] as $folder) {
            if (!is_dir($folder . '/assets')) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder . '/assets', FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && !str_starts_with($file->getFilename(), '.')) {
                    $assets[substr($file->getPathname(), \strlen($folder . '/assets/'))] = $file->getPathname();
                }
            }
        }

        ksort($assets);

        return $assets;
    }

    /**
     * Overrides must point to an existing block or layout, unless the
     * package is not installed or is a theme outside the chain.
     *
     * @param array<string, array<string, Component>> $found
     */
    private function checkOverrides(array $found, DefinitionErrors $errors): void
    {
        $available = array_map(static fn(ExtensionPackage $package): string => $package->name, $this->available());

        foreach ($this->themes->themes as $theme) {
            $packages = glob($theme->path . '/overrides/*/*', \GLOB_ONLYDIR);

            foreach (false === $packages ? [] : $packages as $folder) {
                $package = basename(\dirname($folder)) . '/' . basename($folder);

                if (!\in_array($package, $available, true)) {
                    continue;
                }

                foreach (ComponentKind::cases() as $kind) {
                    foreach ($this->folders($folder . '/' . $kind->folder()) as $name => $path) {
                        if (!isset($found[$kind->value][$package . '/' . $name])) {
                            $errors->add($theme->name . ': overrides/' . $package . '/' . $kind->folder() . '/' . $name, '', \sprintf('%s has no %s "%s"', $package, $kind->value, $name));
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array<string, string> name => path
     */
    private function folders(string $path): array
    {
        $folders = glob($path . '/*', \GLOB_ONLYDIR);
        $result = [];

        foreach (false === $folders ? [] : $folders as $folder) {
            $result[basename($folder)] = $folder;
        }

        return $result;
    }

    /**
     * @return array<mixed>|null Null if the header is broken
     */
    private function header(string $file, string $where, DefinitionErrors $errors): ?array
    {
        try {
            return Header::parse((string) file_get_contents($file));
        } catch (InvalidArgumentException $exception) {
            $errors->add($where, '', $exception->getMessage());

            return null;
        }
    }

    /**
     * @return array<mixed>
     */
    private function yaml(string $file, string $where, DefinitionErrors $errors): array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            $errors->add($where, '', 'not valid YAML: ' . $exception->getMessage());

            return [];
        }

        if (null !== $data && (!\is_array($data) || (array_is_list($data) && [] !== $data))) {
            $errors->add($where, '', 'must contain keys such as title and fields');

            return [];
        }

        return $data ?? [];
    }
}
