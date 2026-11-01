<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<list<PreviewSummary>>
 */
#[AsQuery('preview.list', 'Returns the preview links, newest first, with note, status, address and when they were last opened')]
final readonly class ListPreviews implements Query
{
    /**
     * @return list<PreviewSummary>
     */
    public function handle(PreviewEditor $editor): array
    {
        return $editor->all();
    }
}
