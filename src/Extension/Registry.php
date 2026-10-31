<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Undo\UndoCheck;
use Frootbox\Core\Console\CommandInfo;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Database\SensitiveColumns;
use Frootbox\Core\Event\ListenerProvider;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Routing\Router;
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
 *
 * Migrations in the folder migrations/ of an extension are found
 * automatically.
 */
final class Registry
{
    /** @var list<array{list<string>|string, string, class-string<RequestHandlerInterface>, ?string}> */
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

    /** @var list<class-string<UndoCheck>> */
    private array $undoChecks = [];

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
        $this->routes[] = [$methods, $path, $handler, $name];
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
     */
    public function forPackage(string $package, callable $register): void
    {
        $previous = $this->package;
        $this->package = $package;

        try {
            $register();
        } finally {
            $this->package = $previous;
        }
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

    public function migrations(string $namespace, string $directory): void
    {
        $this->migrations->add($namespace, $directory);
    }

    public function applyRoutes(Router $router): void
    {
        foreach ($this->routes as [$methods, $path, $handler, $name]) {
            $router->add($methods, $path, $handler, $name);
        }
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

    public function sensitiveColumns(): SensitiveColumns
    {
        return $this->sensitive;
    }
}
