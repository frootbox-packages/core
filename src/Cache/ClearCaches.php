<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Cache;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Psr\Log\LoggerInterface;

/**
 * Empties var/cache like bin/frootbox cache:clear, for a website on
 * webspace without a console, e.g. after uploading changed templates.
 *
 * @implements Action<CachesCleared>
 */
#[AsAction('system.cache-clear', 'Empties var/cache: pages for guests, compiled templates and everything else there is rebuilt on the next request', undoable: false, transactional: false)]
final readonly class ClearCaches implements Action
{
    public function handle(CacheFolder $folder, LoggerInterface $logger): CachesCleared
    {
        $files = $folder->clear();
        $logger->info('Cleared the cache: {count} file(s)', ['count' => $files]);

        return new CachesCleared($files);
    }
}
