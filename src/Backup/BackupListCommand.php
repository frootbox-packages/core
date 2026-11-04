<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

use Frootbox\Core\Action\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'backup:list', description: 'Lists the backups of the database, newest first')]
final class BackupListCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1 << 20 => \sprintf('%.1f MB', $bytes / (1 << 20)),
            $bytes >= 1 << 10 => \sprintf('%d KB', intdiv($bytes, 1 << 10)),
            default => $bytes . ' bytes',
        };
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $list = $this->runner->ask(new ListBackups());

        if ([] === $list->backups) {
            $io->writeln('No backups yet.');

            return Command::SUCCESS;
        }

        $io->table(['Name', 'Reason', 'Created', 'Size'], array_map(
            static fn(Backup $backup): array => [$backup->name, $backup->reason->value, $backup->createdAt->format('Y-m-d H:i:s'), self::size($backup->size)],
            $list->backups,
        ));

        if (!$list->canRestore) {
            $io->note('Restore a dump of MySQL or MariaDB with the tools of your host, e.g. phpMyAdmin or mysql.');
        }

        return Command::SUCCESS;
    }
}
