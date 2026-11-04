<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent\Proof;

use Frootbox\Core\ProjectPaths;
use Generator;

/**
 * Collects the proofs in files below var/consent/, one line each, so a
 * choice costs no write to the database (decisions 0175, 0264), like the
 * hits of the statistics. The scheduler moves them to the table. A file
 * stops growing at MOST bytes a day; what is lost with var/ is at most
 * what was not moved yet.
 */
final readonly class ProofLog
{
    public const string DIRECTORY = 'consent';

    public const int MOST = 20_000_000;

    public function __construct(private ProjectPaths $paths) {}

    public function append(Proof $proof): bool
    {
        $directory = $this->paths->var(self::DIRECTORY);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return false;
        }

        $file = $directory . '/' . substr($proof->at, 0, 10) . '.jsonl';
        clearstatcache(true, $file);

        if (is_file($file) && filesize($file) > self::MOST) {
            return false;
        }

        return false !== @file_put_contents($file, $proof->toLine() . "\n", \FILE_APPEND | \LOCK_EX);
    }

    /**
     * Hands the proofs of each file to $keep and removes the file once
     * $keep returned. Only one run at a time; null if another one is busy.
     *
     * @param callable(iterable<Proof>): void $keep
     *
     * @return int|null The number of files
     */
    public function collect(callable $keep): ?int
    {
        $directory = $this->paths->var(self::DIRECTORY);

        if (!is_dir($directory)) {
            return 0;
        }

        $lock = @fopen($directory . '/.lock', 'c');

        if (false === $lock) {
            return null;
        }

        if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
            fclose($lock);

            return null;
        }

        try {
            foreach (self::files($directory . '/*.jsonl') as $file) {
                @rename($file, $file . '.' . bin2hex(random_bytes(4)) . '.work');
            }

            $files = 0;

            foreach (self::files($directory . '/*.work') as $work) {
                $handle = @fopen($work, 'r');

                if (false === $handle) {
                    continue;
                }

                // Waits for a write that began before the file was taken.
                flock($handle, \LOCK_SH);
                $keep(self::read($handle));
                fclose($handle);
                @unlink($work);
                ++$files;
            }

            return $files;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return list<string>
     */
    private static function files(string $pattern): array
    {
        $files = glob($pattern);

        return false === $files ? [] : $files;
    }

    /**
     * @param resource $handle
     *
     * @return Generator<int, Proof>
     */
    private static function read($handle): Generator
    {
        while (false !== ($line = fgets($handle))) {
            $proof = Proof::fromLine(trim($line));

            if (null !== $proof) {
                yield $proof;
            }
        }
    }
}
