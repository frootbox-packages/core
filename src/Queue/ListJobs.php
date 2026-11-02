<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<JobList>
 */
#[AsQuery('system.job-list', 'Lists the jobs of the queue, waiting and failed ones with their last error, and whether cron runs')]
final readonly class ListJobs implements Query
{
    public function handle(QueueOverview $overview): JobList
    {
        return $overview->list();
    }
}
