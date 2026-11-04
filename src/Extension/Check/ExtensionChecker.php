<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Check;

use Composer\Autoload\ClassLoader;
use DI\Container;
use Doctrine\DBAL\Connection;
use FilesystemIterator;
use Frootbox\Core\Action\ActionName;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Kind;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentCheck;
use Frootbox\Core\Component\ComponentException;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\ComponentLoader;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Container\ContainerFactory;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\Extension\ExtensionException;
use Frootbox\Core\Extension\ExtensionLoader;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\ProjectPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Checks one extension package without installing it (decision 0278).
 *
 * It boots the core twice in a throwaway folder with SQLite in memory:
 * once with the installed packages without the target, once with it.
 * What the second has more belongs to the target. The website stays as
 * it is.
 */
final readonly class ExtensionChecker
{
    private const string SQLITE = 'pdo-sqlite:///:memory:';

    private const string THEME = 'A theme brings no actions, queries, tables or migrations. Put them into a module the theme requires.';

    private InstalledPackages $packages;

    /**
     * @param InstalledPackages|null $packages By default those of the registered autoloaders
     */
    public function __construct(
        private Extensions $installed,
        private Config $config,
        ?InstalledPackages $packages = null,
    ) {
        $this->packages = $packages ?? InstalledPackages::fromAutoloaders();
    }

    /**
     * A folder with composer.json and frootbox.yaml, installed or not.
     */
    public function folder(string $path): CheckReport
    {
        $real = realpath($path);

        return $this->check(false === $real ? $path : $real, null);
    }

    /**
     * An installed package by name.
     */
    public function package(string $name): CheckReport
    {
        $package = $this->installed->get($name) ?? throw new ExtensionException(\sprintf('%s is not installed.', $name));

        return $this->check($package->path, $package);
    }

    private function check(string $path, ?ExtensionPackage $installed): CheckReport
    {
        $errors = [];
        $warnings = [];
        $composer = self::composer($path, $errors);
        $name = \is_string($composer['name'] ?? null) ? $composer['name'] : ($installed->name ?? basename($path));
        $license = InstalledPackages::licenses($composer['license'] ?? null);
        $require = \is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $frootbox = \is_string($require['frootbox/core'] ?? null) ? $require['frootbox/core'] : null;
        $version = $installed->version ?? (\is_string($composer['version'] ?? null) ? $composer['version'] : null);

        if (null !== $composer) {
            if (!\is_string($composer['name'] ?? null)) {
                $errors[] = new CheckFinding('composer', 'composer.json', 'There is no "name", e.g. "acme/website".');
            } elseif (1 !== preg_match('#^' . ActionName::PACKAGE . '$#', $name)) {
                $errors[] = new CheckFinding('composer', 'composer.json', \sprintf('"%s" is no valid package name. Use lowercase "vendor/package", e.g. "acme/website".', $name));
            } elseif (null !== $installed && $installed->name !== $name) {
                $errors[] = new CheckFinding('composer', 'composer.json', \sprintf('It names the package "%s", installed is "%s".', $name, $installed->name));
            }

            if ([] === $license) {
                $errors[] = new CheckFinding('composer', 'composer.json', 'There is no "license". Any license will do, e.g. "MIT" or "proprietary".');
            }

            if (null === $frootbox) {
                $warnings[] = new CheckFinding('core', 'composer.json', 'frootbox/core is missing in "require", so Composer does not know which versions of Frootbox it works with, e.g. "frootbox/core": "^0.1".');
            }
        }

        $dependencies = $this->packages->dependencies(array_map(strval(...), array_keys($require)));

        foreach ($dependencies as $dependency) {
            if (!$dependency->installed) {
                $warnings[] = new CheckFinding('license', $dependency->name, 'It is not installed here, so its license is unknown.');
            } elseif ([] === $dependency->license) {
                $warnings[] = new CheckFinding('license', $dependency->name, 'Its license is unknown.');
            } elseif ($dependency->copyleft()) {
                $warnings[] = new CheckFinding('license', $dependency->name, \sprintf('It is under %s, a copyleft license. The website then has to follow it too.', implode(' or ', $dependency->license)));
            }
        }

        $package = [] === $errors ? self::manifest($name, $path, $composer ?? [], $installed, $errors) : null;
        $contents = new CheckContents(translations: self::languages($path . '/translations'), help: self::languages($path . '/help'));

        if (null !== $package?->theme && is_dir($path . '/migrations')) {
            $errors[] = new CheckFinding('theme', 'migrations/', self::THEME);
        }

        if (null !== $package) {
            $contents = $this->boot($package, $installed, $errors, $warnings, $contents);
        }

        return new CheckReport($name, $version, $license, $frootbox, $errors, $warnings, $contents, $dependencies);
    }

    /**
     * Boots without and with the package and compares.
     *
     * @param list<CheckFinding> $errors
     * @param list<CheckFinding> $warnings
     */
    private function boot(ExtensionPackage $package, ?ExtensionPackage $installed, array &$errors, array &$warnings, CheckContents $contents): CheckContents
    {
        $loader = null;

        if (null === $installed) {
            $loader = new ClassLoader();

            foreach ($package->psr4 as $prefix => $directories) {
                $loader->addPsr4($prefix, array_map(static fn(string $directory): string => $package->path . '/' . trim($directory, '/'), $directories));
            }

            // The folder wins over an installed copy of the same package.
            $loader->register(true);
        }

        $root = self::temporaryFolder();

        try {
            $others = $this->without($package->name);
            $theme = $this->config->get('theme');
            $theme = \is_string($theme) ? $theme : null;
            $names = array_map(static fn(ExtensionPackage $other): string => $other->name, $others);
            $parent = \is_string($package->theme['parent'] ?? null) ? $package->theme['parent'] : null;
            $baseTheme = null !== $package->theme ? $parent : $theme;
            $fullTheme = null !== $package->theme ? $package->name : $theme;
            $known = [...$names, $package->name];

            $base = $this->stage($others, \in_array($baseTheme, $names, true) ? $baseTheme : null, $root . '/base');

            if (\is_string($base)) {
                $errors[] = new CheckFinding('load', 'installation', \sprintf('The installation does not load without %s: %s', $package->name, $base));

                return $contents;
            }

            $full = $this->stage([...$others, $package], \in_array($fullTheme, $known, true) ? $fullTheme : null, $root . '/full');

            if (\is_string($full)) {
                $errors[] = str_starts_with($full, 'migrate: ')
                    ? new CheckFinding('migration', 'migrations/', substr($full, 9))
                    : new CheckFinding('load', '', $full);

                return $contents;
            }

            return $this->compare($package, $base, $full, $errors, $warnings, $contents);
        } finally {
            $loader?->unregister();
            self::remove($root);
        }
    }

    /**
     * @param list<CheckFinding> $errors
     * @param list<CheckFinding> $warnings
     */
    private function compare(ExtensionPackage $package, Container $base, Container $full, array &$errors, array &$warnings, CheckContents $contents): CheckContents
    {
        $name = $package->name;
        $actions = [];
        $queries = [];
        $before = self::catalog($base);

        foreach (self::catalog($full) as $action => $kind) {
            if (!\array_key_exists($action, $before)) {
                Kind::Query === $kind ? $queries[] = $action : $actions[] = $action;
            }
        }

        $fieldTypes = array_values(array_diff(self::fieldTypes($full), self::fieldTypes($base)));
        $tables = array_values(array_diff(self::tables($full), self::tables($base)));
        $settings = array_values(array_diff(self::settings($full), self::settings($base)));
        $prefix = str_replace(['/', '-', '.'], '_', $name) . '_';

        foreach ([...$actions, ...$queries, ...$fieldTypes] as $item) {
            if (ActionName::package($item) !== $name) {
                $errors[] = new CheckFinding('naming', $item, \sprintf('The name must start with "%s/".', $name));
            }
        }

        foreach ($tables as $table) {
            if (!str_starts_with($table, $prefix)) {
                $errors[] = new CheckFinding('naming', $table, \sprintf('The table name must start with "%s", e.g. "%sitems".', $prefix, $prefix));
            }
        }

        if (null !== $package->theme && ([] !== $actions || [] !== $queries || [] !== $tables)) {
            $errors[] = new CheckFinding('theme', 'frootbox.yaml', self::THEME);
        }

        [$components, $componentErrors] = self::components($full);
        [, $baseErrors] = self::components($base);

        foreach (array_diff($componentErrors, $baseErrors) as $error) {
            $errors[] = new CheckFinding('component', '', $error);
        }

        $check = $full->get(ComponentCheck::class);
        \assert($check instanceof ComponentCheck);
        $own = array_values(array_filter($components, static fn(Component $component): bool => $component->package() === $name));

        foreach ($check->syntax($own) as $error) {
            $errors[] = new CheckFinding('component', '', $error);
        }

        foreach (array_diff(self::objects($full), self::objects($base)) as $error) {
            $errors[] = new CheckFinding('component', '', $error);
        }

        foreach ($own as $component) {
            foreach ($check->warnings($component) as $warning) {
                $warnings[] = new CheckFinding('component', '', $warning);
            }
        }

        $blocks = [];
        $layouts = [];

        foreach ($own as $component) {
            ComponentKind::Block === $component->kind ? $blocks[] = $component->name : $layouts[] = $component->name;
        }

        return new CheckContents(
            self::sorted($blocks),
            self::sorted($layouts),
            self::sorted($actions),
            self::sorted($queries),
            self::sorted($fieldTypes),
            self::sorted($tables),
            self::sorted($settings),
            $contents->translations,
            $contents->help,
        );
    }

    /**
     * A booted and migrated installation, or the reason it failed ("migrate: …"
     * when only the migrations did).
     *
     * @param list<ExtensionPackage> $packages
     */
    private function stage(array $packages, ?string $theme, string $root): Container|string
    {
        foreach (['config', 'public', 'storage', 'var'] as $folder) {
            mkdir($root . '/' . $folder, 0o777, true);
        }

        $values = [
            'app' => ['name' => 'Check', 'timezone' => $this->config->string('app.timezone', 'Europe/Berlin')],
            'database' => ['url' => self::SQLITE],
            'languages' => $this->config->get('languages') ?? ['en' => ['title' => 'English']],
        ];

        if (null !== $theme) {
            $values['theme'] = $theme;
        }

        try {
            $extensions = ExtensionLoader::arrange($packages);
            $container = ContainerFactory::create(new Config(Environment::Development, $values, ['APP_SECRET' => bin2hex(random_bytes(16))]), new ProjectPaths($root), $extensions, ExtensionLoader::boot($extensions));
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        try {
            $migrator = $container->get(Migrator::class);
            \assert($migrator instanceof Migrator);
            $migrator->migrate();
        } catch (Throwable $exception) {
            return 'migrate: ' . $exception->getMessage();
        }

        return $container;
    }

    /**
     * The installed packages without this one and those that need it.
     *
     * @return list<ExtensionPackage>
     */
    private function without(string $name): array
    {
        $left = [];
        $gone = [$name => true];

        foreach ($this->installed as $package) {
            // Load order puts dependencies first, so one pass is enough.
            if (isset($gone[$package->name]) || [] !== array_intersect($package->requires, array_keys($gone))) {
                $gone[$package->name] = true;

                continue;
            }

            $left[] = $package;
        }

        return $left;
    }

    /**
     * @param list<CheckFinding> $errors
     *
     * @return array<mixed>|null
     */
    private static function composer(string $path, array &$errors): ?array
    {
        $file = $path . '/composer.json';

        if (!is_file($file)) {
            $errors[] = new CheckFinding('composer', 'composer.json', is_dir($path) ? 'The file is missing.' : \sprintf('There is no folder %s.', $path));

            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!\is_array($data)) {
            $errors[] = new CheckFinding('composer', 'composer.json', 'It is no valid JSON: ' . json_last_error_msg());

            return null;
        }

        return $data;
    }

    /**
     * @param array<mixed>       $composer
     * @param list<CheckFinding> $errors
     */
    private static function manifest(string $name, string $path, array $composer, ?ExtensionPackage $installed, array &$errors): ?ExtensionPackage
    {
        if (!is_file($path . '/' . ExtensionPackage::MANIFEST)) {
            $errors[] = new CheckFinding('manifest', ExtensionPackage::MANIFEST, 'The file is missing, so this is no extension.');

            return null;
        }

        $require = \is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $autoload = \is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $psr4 = [];

        foreach (\is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [] as $prefix => $directories) {
            $psr4[(string) $prefix] = array_values(array_filter((array) $directories, \is_string(...)));
        }

        try {
            return ExtensionPackage::fromManifest($name, $path, array_map(strval(...), array_keys($require)), $psr4, null !== $installed && $installed->isProject, $installed?->version);
        } catch (ExtensionException $exception) {
            $errors[] = new CheckFinding('manifest', ExtensionPackage::MANIFEST, $exception->getMessage());

            return null;
        }
    }

    /**
     * @return array<string, Kind>
     */
    private static function catalog(Container $container): array
    {
        $catalog = $container->get(Catalog::class);
        \assert($catalog instanceof Catalog);
        $names = [];

        foreach ($catalog->all() as $descriptor) {
            $names[$descriptor->name] = $descriptor->kind;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function fieldTypes(Container $container): array
    {
        return array_keys(self::registry($container)->fieldTypes());
    }

    /**
     * @return list<string>
     */
    private static function settings(Container $container): array
    {
        return array_keys(self::registry($container)->settingsClasses()->all());
    }

    /**
     * @return list<string>
     */
    private static function tables(Container $container): array
    {
        $connection = $container->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection->createSchemaManager()->listTableNames();
    }

    /**
     * The blocks and layouts, or the errors of loading them.
     *
     * @return array{list<Component>, list<string>}
     */
    private static function components(Container $container): array
    {
        $loader = $container->get(ComponentLoader::class);
        \assert($loader instanceof ComponentLoader);

        try {
            $components = $loader->load();
        } catch (ComponentException $exception) {
            return [[], $exception->errors];
        }

        return [array_values([...$components->blocks, ...$components->layouts]), []];
    }

    /**
     * @return list<string>
     */
    private static function objects(Container $container): array
    {
        $check = $container->get(ComponentCheck::class);
        \assert($check instanceof ComponentCheck);

        try {
            return $check->objects();
        } catch (ComponentException) {
            // Already reported with the components.
            return [];
        }
    }

    private static function registry(Container $container): Registry
    {
        $registry = $container->get(Registry::class);
        \assert($registry instanceof Registry);

        return $registry;
    }

    /**
     * Languages by the files or folders of a folder, e.g. translations/de.yaml,
     * translations/website/fr.yaml or help/en/.
     *
     * @return list<string>
     */
    private static function languages(string $folder): array
    {
        $found = [];

        foreach ([...self::glob($folder . '/*.yaml'), ...self::glob($folder . '/website/*.yaml')] as $file) {
            $found[basename($file, '.yaml')] = true;
        }

        foreach (self::glob($folder . '/*', \GLOB_ONLYDIR) as $directory) {
            if ('website' !== basename($directory)) {
                $found[basename($directory)] = true;
            }
        }

        return self::sorted(array_map(strval(...), array_keys($found)));
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern, int $flags = 0): array
    {
        $found = glob($pattern, $flags);

        return false === $found ? [] : $found;
    }

    /**
     * @param list<string> $items
     *
     * @return list<string>
     */
    private static function sorted(array $items): array
    {
        sort($items);

        return $items;
    }

    private static function temporaryFolder(): string
    {
        return sys_get_temp_dir() . '/frootbox-check-' . bin2hex(random_bytes(6));
    }

    private static function remove(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            \assert($item instanceof SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($root);
    }
}
