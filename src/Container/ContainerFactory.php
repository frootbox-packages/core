<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Container;

use DI\Container;
use DI\ContainerBuilder;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Http\WelcomeHandler;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

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
    public static function create(Config $config, ProjectPaths $paths): Container
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
            Router::class => factory([self::class, 'createRouter']),
        ]);

        $container = $builder->build();

        // Objects cannot be compiled, so they are set after building.
        $container->set(Config::class, $config);
        $container->set(Environment::class, $config->environment);
        $container->set(ProjectPaths::class, $paths);
        $container->set(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * Fixed routes of the core. Extensions will add their own (M1, extension system).
     */
    public static function createRouter(): Router
    {
        $router = new Router();
        $router->get('/', WelcomeHandler::class, 'welcome');

        return $router;
    }
}
