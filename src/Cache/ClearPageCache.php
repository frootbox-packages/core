<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Cache;

use Frootbox\Core\Action\ActionExecuted;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Media\MediaUsages;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;
use Frootbox\Core\Url\NotFoundLog;

/**
 * Clears the page cache after an action that changed what visitors see:
 * every table except drafts, sign-ins, logs and the index of where media
 * are used, which follows the content (decisions 0107, 0146). A
 * revision counts once it is or was published, e.g. the design, which
 * lives only there (decision 0127).
 */
final readonly class ClearPageCache
{
    /** Tables that never change a published page. */
    public const array IGNORED = [Revisions::TABLE, AccessTokens::TABLE, NotFoundLog::TABLE, MediaUsages::TABLE];

    public function __construct(
        private PageCache $cache,
        private ActionLog $log,
    ) {}

    public function __invoke(ActionExecuted $event): void
    {
        $tables = $this->log->changedTables($event->entryId);

        if ([] !== array_diff($tables, self::IGNORED) || (\in_array(Revisions::TABLE, $tables, true) && $this->publishedRevisionChanged($event->entryId))) {
            $this->cache->clear();
        }
    }

    private function publishedRevisionChanged(int $entryId): bool
    {
        foreach ($this->log->changes($entryId) as $change) {
            if (Revisions::TABLE !== $change->target) {
                continue;
            }

            foreach ([$change->oldValues, $change->newValues] as $values) {
                if (RevisionStatus::Draft->value !== ($values['status'] ?? RevisionStatus::Draft->value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
