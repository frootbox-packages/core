<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Tries both stores (decision 0268): write, read, list, delete a small
 * file, and for a public bucket load it from its address as a browser
 * would.
 */
#[AsCommand(name: 'storage:check', description: 'Tries writing, reading and deleting in both stores')]
final class StorageCheckCommand extends Command
{
    public function __construct(private readonly FileStores $stores)
    {
        parent::__construct();
    }

    /**
     * e.g. "12.3 MB"
     */
    public static function size(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || 'GB' === $unit) {
                return 'B' === $unit ? $bytes . ' B' : number_format($bytes, 1) . ' ' . $unit;
            }

            $bytes /= 1024;
        }

        return '';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failed = false;

        foreach (StoreRole::cases() as $role) {
            try {
                $store = $this->stores->of($role);
            } catch (StorageException $error) {
                $io->error($role->variable() . ': ' . $error->getMessage());
                $failed = true;

                continue;
            }

            $io->section(\sprintf('%s: %s', $role->variable(), $store->name()));

            if (!$this->stores->isRemote($role)) {
                $io->writeln('Not set, the folder of the installation.');

                continue;
            }

            $failed = !$this->check($io, $store, $role) || $failed;
        }

        if ($failed) {
            return Command::FAILURE;
        }

        $io->success('Both stores work.');

        return Command::SUCCESS;
    }

    private function check(SymfonyStyle $io, FileStore $store, StoreRole $role): bool
    {
        $path = 'media/frootbox-check-' . bin2hex(random_bytes(6)) . '.txt';
        $contents = 'Frootbox ' . date('c');

        try {
            $store->write($path, $contents, 'text/plain');
            $io->writeln('✓ write');
            $ok = $store->read($path) === $contents;
            $io->writeln(($ok ? '✓' : '✗') . ' read');
            $listed = false;

            foreach ($store->list('media/frootbox-check-') as $file) {
                $listed = $listed || $file->path === $path;
            }

            $io->writeln(($listed ? '✓' : '✗') . ' list');
            $ok = $ok && $listed && $this->checkUrl($io, $store, $role, $path, $contents);
            $store->delete($path);
            $gone = !$store->exists($path);
            $io->writeln(($gone ? '✓' : '✗') . ' delete');

            return $ok && $gone;
        } catch (Throwable $error) {
            $io->error($error->getMessage());

            return false;
        }
    }

    private function checkUrl(SymfonyStyle $io, FileStore $store, StoreRole $role, string $path, string $contents): bool
    {
        $url = $store->url($path);

        if (StoreRole::Storage === $role) {
            if (null !== $url) {
                $io->writeln('✗ the store for data has an address for browsers');

                return false;
            }

            return true;
        }

        if (null === $url) {
            $io->writeln('✗ no address for browsers, set url= in PUBLIC_STORAGE_DSN');

            return false;
        }

        if (!filter_var(\ini_get('allow_url_fopen'), \FILTER_VALIDATE_BOOL)) {
            $io->writeln('? ' . $url . ' not loaded, allow_url_fopen is off');

            return true;
        }

        $loaded = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => false]]));
        $ok = $loaded === $contents;
        $io->writeln(($ok ? '✓ ' : '✗ ') . $url . ($ok ? '' : ' cannot be loaded: is the bucket public?'));

        return $ok;
    }
}
