<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Doctrine\DBAL\Connection;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Frootbox;
use Frootbox\Core\ProjectPaths;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shows version, environment and database state of the installation.
 */
#[AsCommand(name: 'about', description: 'Shows version, environment and database state')]
final class AboutCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly ProjectPaths $paths,
        private readonly Extensions $extensions,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [
            ['Frootbox', Frootbox::VERSION],
            ['PHP', \PHP_VERSION],
            ['Environment', $this->config->environment->value],
            ['Project root', $this->paths->root],
            ['Extensions', (string) \count($this->extensions)],
        ];

        // The database is only touched here, so about also works without one.
        try {
            $connection = $this->container->get(Connection::class);
            $migrator = $this->container->get(Migrator::class);

            if (!$connection instanceof Connection || !$migrator instanceof Migrator) {
                throw new \UnexpectedValueException('Database services are missing in the container.');
            }

            $driver = $connection->getParams()['driver'] ?? null;
            $rows[] = ['Database driver', \is_string($driver) ? $driver : '-'];
            $rows[] = ['Database', 'connected, server ' . $connection->getServerVersion()];
            $rows[] = ['Pending migrations', (string) $migrator->pending()];
            $status = Command::SUCCESS;
        } catch (Throwable $exception) {
            $rows[] = ['Database', 'not reachable: ' . $exception->getMessage()];
            $status = Command::FAILURE;
        }

        $io->table([], $rows);

        return $status;
    }
}
