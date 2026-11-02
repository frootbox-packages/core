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
use Frootbox\Core\Action\Undo\UndoChecks;
use Frootbox\Core\Auth\Permissions;
use Frootbox\Core\Auth\RolePermissions;
use Frootbox\Core\Auth\ScopeResolvers;
use Frootbox\Core\Component\BlockGroups;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ComponentsFactory;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Console\ConsoleFactory;
use Frootbox\Core\Database\ConnectionFactory;
use Frootbox\Core\Database\MigrationsFactory;
use Frootbox\Core\Database\MigrationSources;
use Frootbox\Core\Database\RecordingWriter;
use Frootbox\Core\Database\SensitiveColumns;
use Frootbox\Core\Error\ErrorPages;
use Frootbox\Core\Event\EventDispatcher;
use Frootbox\Core\Event\ListenerProvider;
use Frootbox\Core\Extension\ExtensionLoader;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\I18n\UserLanguages;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Log\LoggerFactory;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Routing\Router;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Url\TargetLink;
use Frootbox\Core\User\StoredUserLanguages;
use Frootbox\Core\View\TwigFactory;
use Frootbox\Database\Writer;
use Frootbox\RichText\RichText;
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
use Twig\Environment as Twig;

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
            Permissions::class => get(RolePermissions::class),
            UserLanguages::class => get(StoredUserLanguages::class),
            SensitiveColumns::class => factory([self::class, 'createSensitiveColumns']),
            FieldTypes::class => factory([self::class, 'createFieldTypes']),
            BlockGroups::class => factory([self::class, 'createBlockGroups']),
            UndoChecks::class => factory([self::class, 'createUndoChecks']),
            ErrorPages::class => factory([self::class, 'createErrorPages']),
            ScopeResolvers::class => factory([self::class, 'createScopeResolvers']),
            ThemeChain::class => factory([self::class, 'createThemeChain']),
            Languages::class => factory([Languages::class, 'fromConfig']),
            Components::class => factory([ComponentsFactory::class, 'create']),
            Twig::class => factory([TwigFactory::class, 'create']),
            RichText::class => factory([self::class, 'createRichText']),
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

    /**
     * Rich text whose links may point to targets such as pages (decision 0105).
     */
    public static function createRichText(ContainerInterface $container): RichText
    {
        return RichText::standard()->withMark(new TargetLink($container));
    }

    public static function createRouter(Registry $registry, Config $config): Router
    {
        $router = new Router();
        $registry->applyRoutes($router, $config);

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

    public static function createSensitiveColumns(Registry $registry): SensitiveColumns
    {
        return $registry->sensitiveColumns();
    }

    public static function createFieldTypes(Registry $registry, ContainerInterface $container): FieldTypes
    {
        return new FieldTypes($registry->fieldTypes(), $container);
    }

    public static function createBlockGroups(Registry $registry): BlockGroups
    {
        return new BlockGroups($registry->blockGroups());
    }

    public static function createScopeResolvers(Registry $registry, ContainerInterface $container): ScopeResolvers
    {
        return new ScopeResolvers($registry->scopeResolvers(), $container);
    }

    public static function createUndoChecks(Registry $registry, ContainerInterface $container): UndoChecks
    {
        return new UndoChecks($registry->undoChecks(), $container);
    }

    public static function createErrorPages(Registry $registry, ContainerInterface $container, LoggerInterface $logger): ErrorPages
    {
        return new ErrorPages($registry->errorPages(), $container, $logger);
    }

    /**
     * The active theme comes from "theme:" in config/app.yaml.
     */
    public static function createThemeChain(Extensions $extensions, Config $config): ThemeChain
    {
        $theme = $config->get('theme');

        return ThemeChain::resolve($extensions, \is_string($theme) ? $theme : null);
    }

    public static function createListenerProvider(ContainerInterface $container, Registry $registry): ListenerProvider
    {
        $provider = new ListenerProvider($container);
        $registry->applyListeners($provider);

        return $provider;
    }
}
