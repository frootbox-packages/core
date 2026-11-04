<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Backup;

/**
 * Packs and unpacks backups as streams, so a large database never has to
 * fit into memory.
 */
final class Gzip
{
    private const int CHUNK = 1 << 20;

    public static function pack(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        if (false === $in || false === $out) {
            throw new BackupException(\sprintf('Could not pack %s.', basename($source)));
        }

        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);

                if (false === $chunk || (\strlen($chunk) > 0 && false === gzwrite($out, $chunk))) {
                    throw new BackupException(\sprintf('Could not pack %s.', basename($source)));
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    public static function unpack(string $source, string $target): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($target, 'wb');

        if (false === $in || false === $out) {
            throw new BackupException(\sprintf('Could not unpack %s.', basename($source)));
        }

        try {
            while (!gzeof($in)) {
                $chunk = gzread($in, self::CHUNK);

                if (false === $chunk || false === fwrite($out, $chunk)) {
                    throw new BackupException(\sprintf('Could not unpack %s.', basename($source)));
                }
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }
}
