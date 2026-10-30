<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command as Migrations;
use Doctrine\Migrations\Tools\Console\Command\DoctrineCommand;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Frootbox;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;

/**
 * Builds bin/frootbox with the commands of the core and the extensions.
 *
 * Every command is created only when it runs. So bin/frootbox also works
 * without a database, e.g. for make:extension or a later install command.
 * The Doctrine commands (migrations:*) are for details such as status,
 * rollback or new migrations and keep only their prefixed names.
 */
final class ConsoleFactory
{
    /** @var list<class-string<DoctrineCommand>> */
    private const array DOCTRINE_COMMANDS = [
        Migrations\CurrentCommand::class,
        Migrations\ExecuteCommand::class,
        Migrations\GenerateCommand::class,
        Migrations\LatestCommand::class,
        Migrations\ListCommand::class,
        Migrations\MigrateCommand::class,
        Migrations\StatusCommand::class,
        Migrations\SyncMetadataCommand::class,
        Migrations\UpToDateCommand::class,
        Migrations\VersionCommand::class,
    ];

    public static function create(ContainerInterface $container, Registry $registry): ConsoleApplication
    {
        $console = new ConsoleApplication('Frootbox', Frootbox::VERSION);

        foreach ($registry->commands() as $class) {
            $console->addCommand(self::lazy($class, static fn(): Command => self::fromContainer($container, $class)));
        }

        foreach (self::DOCTRINE_COMMANDS as $class) {
            $console->addCommand(self::lazy($class, static fn(): Command => new $class(self::migrations($container))));
        }

        return $console;
    }

    /**
     * @param class-string<Command> $class
     * @param \Closure(): Command   $factory
     */
    private static function lazy(string $class, \Closure $factory): LazyCommand
    {
        $info = CommandInfo::of($class);

        // Aliases are left out on purpose: Doctrine would otherwise take
        // short names such as "migrate" or "status".
        return new LazyCommand($info->name, [], $info->description, $info->hidden, $factory);
    }

    /**
     * @param class-string<Command> $class
     */
    private static function fromContainer(ContainerInterface $container, string $class): Command
    {
        $command = $container->get($class);

        if (!$command instanceof Command) {
            throw new \UnexpectedValueException(\sprintf('Service %s is not a console command.', $class));
        }

        return $command;
    }

    private static function migrations(ContainerInterface $container): DependencyFactory
    {
        $factory = $container->get(DependencyFactory::class);

        if (!$factory instanceof DependencyFactory) {
            throw new \UnexpectedValueException('The container returned no Doctrine DependencyFactory.');
        }

        return $factory;
    }
}
