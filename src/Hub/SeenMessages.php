<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Security\StandardWebhooks;

/**
 * Remembers the webhook-id of each message from the hub for as long as
 * its time is accepted, so each one counts only once (decision 0284). One
 * empty file per id in var/hub/messages/: made atomically and allowed to
 * get lost with var/, which only opens the window of the tolerance again.
 */
final readonly class SeenMessages
{
    public function __construct(private ProjectPaths $paths) {}

    /**
     * Whether the id comes for the first time.
     */
    public function first(string $id): bool
    {
        $folder = $this->paths->var('hub/messages');

        if (!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) {
            return false;
        }

        $this->prune($folder);
        $handle = @fopen($folder . '/' . hash('sha256', $id), 'x');

        if (false === $handle) {
            return false;
        }

        fclose($handle);

        return true;
    }

    private function prune(string $folder): void
    {
        $before = time() - 2 * StandardWebhooks::TOLERANCE;

        $files = glob($folder . '/*');

        foreach (false === $files ? [] : $files as $file) {
            if ((int) @filemtime($file) < $before) {
                @unlink($file);
            }
        }
    }
}
