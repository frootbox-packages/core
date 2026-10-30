<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Container;

use DI\Container;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Auth\Permissions;
use Frootbox\Core\Auth\SystemPermissions;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Console\ConsoleFactory;
use Frootbox\Core\Database\ConnectionFactory;
use Frootbox\Core\Database\MigrationsFactory;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Database\RecordingWriter;
use Frootbox\Core\Event\EventDispatcher;
use Frootbox\Core\Event\ListenerProvider;
use Frootbox\Core\Extension\ExtensionLoader;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Log\LoggerFactory;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Routing\Router;
use Frootbox\Database\Writer;
use Invoker\InvokerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;

use function DI\factory;
use function DI\get;

/**
 * Builds the service container (PHP-DI with autowiring).
 *
 * In production the container is compiled to var/cache/container. In
 * development it is built on every request, so changes show up immediately.
 */
final class ContainerFactory
{
    public static function create(Config $config, ProjectPaths $paths, Extensions $extensions = new Extensions(), ?Registry $registry = null): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);

        if (!$config->environment->isDevelopment()) {
            $builder->enableCompilation($paths->var('cache/container'));
        }

        $builder->addDefinitions([
            ResponseFactoryInterface::class => get(Psr17Factory::class),
            StreamFactoryInterface::class => get(Psr17Factory::class),
            ServerRequestFactoryInterface::class => get(Psr17Factory::class),
            UriFactoryInterface::class => get(Psr17Factory::class),
            UploadedFileFactoryInterface::class => get(Psr17Factory::class),
            LoggerInterface::class => factory([LoggerFactory::class, 'create']),
            EventDispatcherInterface::class => get(EventDispatcher::class),
            ListenerProvider::class => factory([self::class, 'createListenerProvider']),
            ListenerProviderInterface::class => get(ListenerProvider::class),
            Router::class => factory([self::class, 'createRouter']),
            Connection::class => factory([ConnectionFactory::class, 'create']),
            // Repositories write only inside actions and record every change.
            Writer::class => get(RecordingWriter::class),
            MigrationSources::class => factory([self::class, 'createMigrationSources']),
            DependencyFactory::class => factory([MigrationsFactory::class, 'create']),
            ConsoleApplication::class => factory([ConsoleFactory::class, 'create']),
            Catalog::class => factory([self::class, 'createCatalog']),
            Permissions::class => get(SystemPermissions::class),
        ]);

        $container = $builder->build();

        // Objects cannot be compiled, so they are set after building.
        $container->set(Config::class, $config);
        $container->set(Environment::class, $config->environment);
        $container->set(ProjectPaths::class, $paths);
        $container->set(Extensions::class, $extensions);
        $container->set(Registry::class, $registry ?? ExtensionLoader::boot($extensions));
        $container->set(ContainerInterface::class, $container);
        $container->set(InvokerInterface::class, $container);

        return $container;
    }

    public static function createRouter(Registry $registry): Router
    {
        $router = new Router();
        $registry->applyRoutes($router);

        return $router;
    }

    public static function createMigrationSources(Registry $registry): MigrationSources
    {
        return $registry->migrationSources();
    }

    public static function createCatalog(Registry $registry): Catalog
    {
        return $registry->catalog();
    }

    public static function createListenerProvider(ContainerInterface $container, Registry $registry): ListenerProvider
    {
        $provider = new ListenerProvider($container);
        $registry->applyListeners($provider);

        return $provider;
    }
}
