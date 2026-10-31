<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Cache;

use Frootbox\Core\Action\ActionExecuted;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Url\NotFoundLog;

/**
 * Clears the page cache after an action that changed what visitors see:
 * every table except drafts, sign-ins and logs (decision 0107).
 */
final readonly class ClearPageCache
{
    /** Tables that never change a published page. */
    public const array IGNORED = [Revisions::TABLE, AccessTokens::TABLE, NotFoundLog::TABLE];

    public function __construct(
        private PageCache $cache,
        private ActionLog $log,
    ) {}

    public function __invoke(ActionExecuted $event): void
    {
        if ([] !== array_diff($this->log->changedTables($event->entryId), self::IGNORED)) {
            $this->cache->clear();
        }
    }
}
