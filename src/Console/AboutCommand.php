<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Frootbox;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Site\SiteProtection;
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

        $protection = null;

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
            $protection = $this->protection();
            $rows[] = ['Password protection', $protection ?? 'unknown, not migrated yet'];
            $status = Command::SUCCESS;
        } catch (Throwable $exception) {
            $rows[] = ['Database', 'not reachable: ' . $exception->getMessage()];
            $status = Command::FAILURE;
        }

        $io->table([], $rows);

        // The web server delivers a real file itself, the protection cannot answer "Disallow: /" then (decision 0153).
        if (null !== $protection && str_starts_with($protection, 'on') && is_file($this->paths->public('robots.txt'))) {
            $io->warning('public/robots.txt is delivered by the web server, so search engines do not get "Disallow: /" while the website is protected. Remove it until the launch.');
        }

        return $status;
    }

    /**
     * Whether the website is protected by a password (decisions 0153 and
     * 0154), null if its table is missing.
     */
    private function protection(): ?string
    {
        $protection = $this->container->get(SiteProtection::class);

        if (!$protection instanceof SiteProtection) {
            throw new \UnexpectedValueException('The site protection is missing in the container.');
        }

        try {
            $status = $protection->status();
        } catch (TableNotFoundException) {
            return null;
        }

        return match (true) {
            !$status->on => 'off',
            $status->forced && !$status->password => 'on, forced by SITE_PROTECT, without a password: only signed-in users and preview links',
            $status->forced => 'on, forced by SITE_PROTECT',
            default => 'on' . (null === $status->since ? '' : ' since ' . $status->since->format('Y-m-d H:i')),
        };
    }
}
