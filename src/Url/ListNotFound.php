<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Range;

/**
 * @implements Query<list<NotFoundEntry>>
 */
#[AsQuery('not-found.list', 'Returns paths that found nothing, the most frequent first, with count and first and last request')]
final readonly class ListNotFound implements Query
{
    public function __construct(
        #[Range(min: 1, max: NotFoundLog::MAX)]
        public int $limit = 100,
    ) {}

    /**
     * @return list<NotFoundEntry>
     */
    public function handle(NotFoundLog $log): array
    {
        return $log->entries($this->limit);
    }
}
