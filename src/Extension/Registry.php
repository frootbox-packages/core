<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Undo\UndoCheck;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\ScopeResolver;
use Frootbox\Core\Component\BlockGroup;
use Frootbox\Core\Component\BlockGroups;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Console\CommandInfo;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Database\SensitiveColumns;
use Frootbox\Core\Error\ErrorPage;
use Frootbox\Core\Event\ListenerProvider;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Routing\Router;
use Frootbox\Core\Search\SearchSource;
use Frootbox\Core\Search\SearchSources;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Collects what the core and the extensions bring along:
 *
 *     $registry->get('/feed.xml', FeedHandler::class);
 *     $registry->listen(PagePublished::class, ClearFeed::class);
 *     $registry->command(ImportCommand::class);
 *     $registry->action(PublishNews::class);
 *     $registry->query(ListNews::class);
 *     $registry->fieldType(PriceType::class);
 *     $registry->undoCheck(OrderNumbersCheck::class);
 *     $registry->errorPage(ShopErrorPage::class);
 *     $registry->mount('backend.path', '/cms')->get('/login', LoginHandler::class, 'backend.login');
 *     $registry->import('lit', 'assets/build/lit.js');
 *     $registry->asset('assets/css/shop.css', 'backend');
 *     $registry->add(Screen::class, new Screen(…));
 *
 * Migrations in the folder migrations/ of an extension are found
 * automatically.
 */
final class Registry
{
    /** A path below which an installation may move routes, e.g. "/cms". */
    private const string MOUNT_PATTERN = '~^(/[a-z0-9]+(-[a-z0-9]+)*)+$~';

    /** @var list<array{list<string>|string, string, class-string<RequestHandlerInterface>, ?string, ?Mount}> */
    private array $routes = [];

    /** @var list<array{class-string, object|non-empty-string|array{non-empty-string, non-empty-string}, int}> */
    private array $listeners = [];

    /** @var array<string, class-string<Command>> */
    private array $commands = [];

    private MigrationSources $migrations;

    private Catalog $catalog;

    /** The package whose extension is registering, null for the core. */
    private ?string $package = null;

    private SensitiveColumns $sensitive;

    /** @var array<string, class-string<FieldType>> */
    private array $fieldTypes = [];

    /** @var array<string, class-string<ScopeResolver>> by kind */
    private array $scopes = [];

    /** @var list<class-string<UndoCheck>> */
    private array $undoChecks = [];

    /** @var list<class-string<ErrorPage>> */
    private array $errorPages = [];

    /** @var list<class-string<SearchSource>> */
    private array $searchSources = [];

    /** The folder of the package that is registering, null for the core. */
    private ?string $path = null;

    /** @var array<string, array{string, string}> name => [file, package] */
    private array $imports = [];

    /** @var array<string, list<string>> area => files */
    private array $assets = [];

    /** @var array<class-string, list<object>> */
    private array $items = [];

    /** @var array<string, BlockGroup> */
    private array $blockGroups = [];

    public function __construct()
    {
        $this->migrations = new MigrationSources();
        $this->catalog = new Catalog();
        $this->sensitive = new SensitiveColumns();
    }

    /**
     * @param list<string>|string                     $methods
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function route(array|string $methods, string $path, string $handler, ?string $name = null): void
    {
        $this->routes[] = [$methods, $path, $handler, $name, null];
    }

    /**
     * Routes below a path the installation can change in config/app.yaml,
     * e.g. mount('backend.path', '/cms'). Their first segment is reserved
     * for pages (decision 0114).
     */
    public function mount(string $configKey, string $default): Mount
    {
        if (1 !== preg_match(self::MOUNT_PATTERN, $default)) {
            throw new ExtensionException(\sprintf('The mount path "%s" must start with a slash and use lower case a-z, 0-9 and hyphens, e.g. "/cms".', $default));
        }

        return new Mount($this, $configKey, $default);
    }

    /**
     * @internal Called by Mount.
     *
     * @param list<string>|string                   $methods
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function mountedRoute(Mount $mount, array|string $methods, string $path, string $handler, ?string $name): void
    {
        $this->routes[] = [$methods, $path, $handler, $name, $mount];
    }

    /**
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function get(string $path, string $handler, ?string $name = null): void
    {
        $this->route('GET', $path, $handler, $name);
    }

    /**
     * @param class-string<RequestHandlerInterface> $handler
     */
    public function post(string $path, string $handler, ?string $name = null): void
    {
        $this->route('POST', $path, $handler, $name);
    }

    /**
     * @param class-string                                                       $event
     * @param object|non-empty-string|array{non-empty-string, non-empty-string} $listener See ListenerProvider::listen()
     */
    public function listen(string $event, object|string|array $listener, int $priority = 0): void
    {
        $this->listeners[] = [$event, $listener, $priority];
    }

    /**
     * A console command for bin/frootbox. Its name comes from #[AsCommand].
     *
     * @param class-string<Command> $class
     */
    public function command(string $class): void
    {
        $name = CommandInfo::of($class)->name;

        if (isset($this->commands[$name])) {
            throw new ExtensionException(\sprintf('The command "%s" is registered twice: %s and %s.', $name, $this->commands[$name], $class));
        }

        $this->commands[$name] = $class;
    }

    /**
     * Runs the registration of an extension package. Its actions and
     * queries must be named "<package>/…".
     *
     * @internal Called by the ExtensionLoader.
     *
     * @param callable(): void $register
     * @param string|null      $path      the folder of the package, for import() and asset()
     */
    public function forPackage(string $package, callable $register, ?string $path = null): void
    {
        [$previousPackage, $previousPath] = [$this->package, $this->path];
        $this->package = $package;
        $this->path = $path;

        try {
            $register();
        } finally {
            [$this->package, $this->path] = [$previousPackage, $previousPath];
        }
    }

    /**
     * An entry of the import map, so scripts can `import { html } from "lit"`
     * (decision 0111). The file is relative to the package and must be in
     * an assets/ folder. A name belongs to one package only.
     */
    public function import(string $name, string $file): void
    {
        $package = $this->package ?? 'frootbox/core';

        if (isset($this->imports[$name])) {
            throw new ExtensionException(\sprintf('The import "%s" is registered by %s and %s. A name belongs to one package only.', $name, $this->imports[$name][1], $package));
        }

        $this->imports[$name] = [$this->file($file), $package];
    }

    /**
     * A style or script loaded on every page of an area, e.g. "backend".
     * The file is relative to the package.
     */
    public function asset(string $file, string $area): void
    {
        $this->assets[$area][] = $this->file($file);
    }

    /**
     * Registers an object of a type that the core does not need to know,
     * e.g. a screen of the backend or a brand. items() returns them.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     * @param T               $item
     */
    public function add(string $type, object $item): void
    {
        if (!$item instanceof $type) {
            throw new ExtensionException(\sprintf('%s is no %s.', $item::class, $type));
        }

        $this->items[$type][] = $item;
    }

    /**
     * Everything added for a type, in load order: the core first, project
     * extensions last.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public function items(string $type): array
    {
        return array_values(array_filter($this->items[$type] ?? [], static fn(object $item): bool => $item instanceof $type));
    }

    /**
     * An action. Its name, description and permission come from #[AsAction].
     * An extension names it "<vendor>/<package>/…", e.g.
     * "acme/shop/order.create".
     *
     * @param class-string<Action<mixed>> $class
     */
    public function action(string $class): void
    {
        if (!$this->catalog->add($class, $this->package)->isAction()) {
            throw new DefinitionException(\sprintf('%s is a query. Register it with query().', $class));
        }
    }

    /**
     * A query. Its name, description and permission come from #[AsQuery].
     *
     * @param class-string<Query<mixed>> $class
     */
    public function query(string $class): void
    {
        if ($this->catalog->add($class, $this->package)->isAction()) {
            throw new DefinitionException(\sprintf('%s is an action. Register it with action().', $class));
        }
    }

    /**
     * Columns such as password hashes that are recorded only as "***" for
     * undoing, e.g. sensitive('users', 'password').
     */
    public function sensitive(string $table, string ...$columns): void
    {
        $this->sensitive->add($table, ...$columns);
    }

    /**
     * A field type for blocks and layouts. Its name comes from
     * #[AsFieldType]; an extension names it "<vendor>/<package>/…", e.g.
     * "acme/shop/price".
     *
     * @param class-string<FieldType> $class
     */
    public function fieldType(string $class): void
    {
        $name = FieldTypes::nameOf($class);
        $slash = strrpos($name, '/');
        $package = false === $slash ? null : substr($name, 0, $slash);

        if ($package !== $this->package) {
            throw new DefinitionException(null === $this->package
                ? \sprintf('The core field type %s must not have a package in its name "%s".', $class, $name)
                : \sprintf('The field type %s of %s must be named "%s/%s".', $class, $this->package, $this->package, false === $slash ? $name : substr($name, $slash + 1)));
        }

        if (isset($this->fieldTypes[$name])) {
            throw new DefinitionException(\sprintf('The field type "%s" is registered twice: %s and %s.', $name, $this->fieldTypes[$name], $class));
        }

        $this->fieldTypes[$name] = $class;
    }

    /**
     * A group of the block picker (decision 0148), e.g.
     * blockGroup('acme/shop/products', 'Products'). It follows the groups
     * of the core, in load order. Blocks name it with "group:".
     */
    public function blockGroup(string $name, string $title): void
    {
        $slash = strrpos($name, '/');
        $package = false === $slash ? null : substr($name, 0, $slash);

        if (!BlockGroup::isValid($name) || null === $package || $package !== $this->package) {
            throw new DefinitionException(null === $this->package
                ? 'The groups of the core are fixed in BlockGroups::CORE.'
                : \sprintf('The block group "%s" of %s must be named "%s/<group>", lower case with dashes.', $name, $this->package, $this->package));
        }

        if (isset($this->blockGroups[$name]) || isset(BlockGroups::CORE[$name])) {
            throw new DefinitionException(\sprintf('The block group "%s" is registered twice.', $name));
        }

        $this->blockGroups[$name] = new BlockGroup($name, $title);
    }

    /**
     * A check that runs after an action was undone and refuses the undo
     * if it would break a rule across several rows (decision 0102).
     *
     * @param class-string<UndoCheck> $class
     */
    public function undoCheck(string $class): void
    {
        if (!is_subclass_of($class, UndoCheck::class)) {
            throw new DefinitionException(\sprintf('%s must implement %s.', $class, UndoCheck::class));
        }

        if (!\in_array($class, $this->undoChecks, true)) {
            $this->undoChecks[] = $class;
        }
    }

    /**
     * An error page for the requests it is responsible for, e.g. those of
     * the backend (decision 0132). The first responsible one wins.
     *
     * @param class-string<ErrorPage> $class
     */
    public function errorPage(string $class): void
    {
        if (!is_subclass_of($class, ErrorPage::class)) {
            throw new DefinitionException(\sprintf('%s must implement %s.', $class, ErrorPage::class));
        }

        if (!\in_array($class, $this->errorPages, true)) {
            $this->errorPages[] = $class;
        }
    }

    /**
     * A kind of scope roles can be given for, e.g. "page" for branches of
     * the page tree (decision 0118).
     *
     * @param class-string<ScopeResolver> $class
     */
    public function scope(string $kind, string $class): void
    {
        if (1 !== preg_match('/' . Scope::KIND_PATTERN . '/', $kind)) {
            throw new DefinitionException(\sprintf('The scope kind "%s" must be lowercase letters, digits and dashes.', $kind));
        }

        if (!is_subclass_of($class, ScopeResolver::class)) {
            throw new DefinitionException(\sprintf('%s must implement %s.', $class, ScopeResolver::class));
        }

        if (isset($this->scopes[$kind]) && $this->scopes[$kind] !== $class) {
            throw new DefinitionException(\sprintf('The scope kind "%s" is registered twice: %s and %s.', $kind, $this->scopes[$kind], $class));
        }

        $this->scopes[$kind] = $class;
    }

    /**
     * A source of the global search (decision 0214). Its name comes from
     * #[AsSearchSource]; an extension names it "<vendor>/<package>/…",
     * e.g. "acme/news/article".
     *
     * @param class-string<SearchSource> $class
     */
    public function searchSource(string $class): void
    {
        if (!is_subclass_of($class, SearchSource::class)) {
            throw new DefinitionException(\sprintf('%s must implement %s.', $class, SearchSource::class));
        }

        $name = SearchSources::nameOf($class);
        $slash = strrpos($name, '/');
        $package = false === $slash ? null : substr($name, 0, $slash);

        if ($package !== $this->package) {
            throw new DefinitionException(null === $this->package
                ? \sprintf('The core search source %s must not have a package in its name "%s".', $class, $name)
                : \sprintf('The search source %s of %s must be named "%s/%s".', $class, $this->package, $this->package, false === $slash ? $name : substr($name, $slash + 1)));
        }

        foreach ($this->searchSources as $known) {
            if (SearchSources::nameOf($known) === $name) {
                throw new DefinitionException(\sprintf('The search source "%s" is registered twice: %s and %s.', $name, $known, $class));
            }
        }

        $this->searchSources[] = $class;
    }

    public function migrations(string $namespace, string $directory): void
    {
        $this->migrations->add($namespace, $directory);
    }

    /**
     * Adds the routes, those of a mount below its configured path.
     */
    public function applyRoutes(Router $router, ?Config $config = null): void
    {
        $prefixes = [];

        foreach ($this->routes as [$methods, $path, $handler, $name, $mount]) {
            if (null !== $mount) {
                $prefix = $prefixes[$mount->configKey] ??= self::mountPath($mount, $config);
                $path = '/' === $path || '' === $path ? $prefix : $prefix . $path;
            }

            $router->add($methods, $path, $handler, $name);
        }
    }

    /**
     * @return array<string, string> name => file
     */
    public function imports(): array
    {
        return array_map(static fn(array $import): string => $import[0], $this->imports);
    }

    /**
     * @return list<string>
     */
    public function assets(string $area): array
    {
        return $this->assets[$area] ?? [];
    }

    public function applyListeners(ListenerProvider $provider): void
    {
        foreach ($this->listeners as [$event, $listener, $priority]) {
            $provider->listen($event, $listener, $priority);
        }
    }

    /**
     * @return array<string, class-string<Command>> command name => class
     */
    public function commands(): array
    {
        return $this->commands;
    }

    public function migrationSources(): MigrationSources
    {
        return $this->migrations;
    }

    public function catalog(): Catalog
    {
        return $this->catalog;
    }

    /**
     * @return list<BlockGroup>
     */
    public function blockGroups(): array
    {
        return array_values($this->blockGroups);
    }

    /**
     * @return array<string, class-string<FieldType>> name => class
     */
    public function fieldTypes(): array
    {
        return $this->fieldTypes;
    }

    /**
     * @return list<class-string<UndoCheck>>
     */
    public function undoChecks(): array
    {
        return $this->undoChecks;
    }

    /**
     * @return list<class-string<ErrorPage>>
     */
    public function errorPages(): array
    {
        return $this->errorPages;
    }

    /**
     * @return array<string, class-string<ScopeResolver>>
     */
    public function scopeResolvers(): array
    {
        return $this->scopes;
    }

    /**
     * @return list<class-string<SearchSource>>
     */
    public function searchSources(): array
    {
        return $this->searchSources;
    }

    public function sensitiveColumns(): SensitiveColumns
    {
        return $this->sensitive;
    }

    private static function mountPath(Mount $mount, ?Config $config): string
    {
        $path = rtrim($config?->string($mount->configKey, $mount->default) ?? $mount->default, '/');

        if (1 !== preg_match(self::MOUNT_PATTERN, $path)) {
            throw new ConfigException(\sprintf('"%s" must be a path such as "%s": a slash, then lower case a-z, 0-9 and hyphens.', $mount->configKey, $mount->default));
        }

        return $path;
    }

    /**
     * An absolute path, or one relative to the package that is registering.
     */
    private function file(string $file): string
    {
        if (str_starts_with($file, '/')) {
            return $file;
        }

        $root = $this->path ?? (null === $this->package ? \dirname(__DIR__, 2) : null);

        if (null === $root) {
            throw new ExtensionException(\sprintf('"%s" of %s must be an absolute path: the folder of the package is unknown.', $file, $this->package));
        }

        return $root . '/' . $file;
    }
}
