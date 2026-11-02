<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A throwaway installation folder for tests.
 */
final readonly class TemporaryProject
{
    private function __construct(public string $root) {}

    public static function create(?string $appYaml = "app:\n  name: Testsite\n", ?string $dotenv = null): self
    {
        $root = sys_get_temp_dir() . '/frootbox-test-' . bin2hex(random_bytes(6));

        foreach (['config', 'public', 'storage', 'var'] as $folder) {
            if (!mkdir($root . '/' . $folder, 0o777, true) && !is_dir($root . '/' . $folder)) {
                throw new RuntimeException(\sprintf('Could not create "%s".', $root));
            }
        }

        if (null !== $appYaml) {
            file_put_contents($root . '/config/app.yaml', $appYaml);
        }

        if (null !== $dotenv) {
            file_put_contents($root . '/.env', $dotenv);
        }

        return new self($root);
    }

    /**
     * Contents of the log files of one environment, empty if there are none.
     */
    public function log(string $environment): string
    {
        $files = glob($this->root . '/var/log/' . $environment . '-*.log');

        return implode('', array_map(static fn(string $file): string => (string) file_get_contents($file), false === $files ? [] : $files));
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            \assert($item instanceof SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }
}
