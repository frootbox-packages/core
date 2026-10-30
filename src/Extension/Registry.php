<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Frootbox\Core\Console\CommandInfo;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Event\ListenerProvider;
use Frootbox\Core\Routing\Router;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Collects what the core and the extensions bring along:
 *
 *     $registry->get('/feed.xml', FeedHandler::class);
 *     $registry->listen(PagePublished::class, ClearFeed::class);
 *     $registry->command(ImportCommand::class);
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

    public function __construct()
    {
        $this->migrations = new MigrationSources();
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
}
