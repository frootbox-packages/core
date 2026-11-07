<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\ProjectPaths;

/**
 * Remembers the jti of each logout token, so each one counts only once
 * (decision 0322). One empty file per id in var/oidc/tokens/, like the
 * messages of the hub.
 */
final readonly class SeenTokens
{
    /** Logout tokens older than this are refused anyway. */
    public const int KEEP = 600;

    public function __construct(private ProjectPaths $paths) {}

    public function first(string $id): bool
    {
        $folder = $this->paths->var('oidc/tokens');

        if (!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) {
            return false;
        }

        $before = time() - self::KEEP;
        $files = glob($folder . '/*');

        foreach (false === $files ? [] : $files as $file) {
            if ((int) @filemtime($file) < $before) {
                @unlink($file);
            }
        }

        $handle = @fopen($folder . '/' . hash('sha256', $id), 'x');

        if (false === $handle) {
            return false;
        }

        fclose($handle);

        return true;
    }
}
