<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<QueueStatus>
 */
#[AsQuery('system.queue-status', 'Returns how many jobs are due or failed and whether cron runs')]
final readonly class ShowQueueStatus implements Query
{
    public function handle(QueueOverview $overview): QueueStatus
    {
        return $overview->status();
    }
}
