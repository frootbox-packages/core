<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command as Migrations;
use Frootbox\Core\Frootbox;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;

/**
 * Builds bin/frootbox with the commands of the core.
 *
 * Frootbox commands load lazily from the container. The Doctrine commands
 * (migrations:*) are for details such as status, rollback or new migrations
 * and keep only their prefixed names.
 */
final class ConsoleFactory
{
    /**
     * Command name => service id.
     *
     * @var array<string, class-string>
     */
    private const array COMMANDS = [
        'about' => AboutCommand::class,
        'migrate' => MigrateCommand::class,
    ];

    public static function create(ContainerInterface $container, DependencyFactory $migrations): ConsoleApplication
    {
        $console = new ConsoleApplication('Frootbox', Frootbox::VERSION);
        $console->setCommandLoader(new ContainerCommandLoader($container, self::COMMANDS));

        $doctrineCommands = [
            new Migrations\CurrentCommand($migrations),
            new Migrations\ExecuteCommand($migrations),
            new Migrations\GenerateCommand($migrations),
            new Migrations\LatestCommand($migrations),
            new Migrations\ListCommand($migrations),
            new Migrations\MigrateCommand($migrations),
            new Migrations\StatusCommand($migrations),
            new Migrations\SyncMetadataCommand($migrations),
            new Migrations\UpToDateCommand($migrations),
            new Migrations\VersionCommand($migrations),
        ];

        // Doctrine adds short aliases such as "migrate" or "status". They
        // would shadow Frootbox commands, so only migrations:* stays.
        foreach ($doctrineCommands as $command) {
            $console->addCommand($command->setAliases([]));
        }

        return $console;
    }
}
