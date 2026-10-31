<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * Compares the table urls with the published pages again, e.g. after
 * the languages in the configuration changed. The next request does this
 * on its own (decision 0105).
 *
 * @implements Action<int>
 */
#[AsAction('system.sync-urls', 'Brings the stored URLs of all pages up to date and returns how many changed')]
final readonly class SyncUrls implements Action
{
    public function handle(AddressSync $sync): int
    {
        $changed = $sync->sync();
        $sync->markFresh();

        return $changed;
    }
}
